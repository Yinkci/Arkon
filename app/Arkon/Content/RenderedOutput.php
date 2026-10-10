<?php

namespace App\Arkon\Content;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads what a headless client needs from a page's rendered HTML: the main content (without the
 * site's shared header and footer), its stylesheet and the SEO metadata of the head. The HTML is
 * Arkon's own serializer output (escaped text, double-quoted attributes, one <main>), so this is
 * exact, and it is what visitors are served: the API never reports SEO the page does not have.
 *
 * Publications are immutable, so their extracts are cached by publication id; entries expire after
 * 30 days only to keep the cache bounded (an expired one is simply read again).
 */
final class RenderedOutput
{
    private const TTL = 60 * 60 * 24 * 30;

    /** @return array{html: string, css: string, seo: array} */
    public static function forPublication(string $publicationId, callable $html): array
    {
        return Cache::remember(self::key($publicationId), self::TTL, fn () => self::of($html()));
    }

    /**
     * Extracts for many publications: cached ones from the cache, the rest read in one query.
     *
     * @param  list<string>  $publicationIds
     * @return array<string, array{html: string, css: string, seo: array}> by publication id
     */
    public static function forPublications(array $publicationIds): array
    {
        $publicationIds = array_values(array_unique($publicationIds));
        if ($publicationIds === []) {
            return [];
        }
        $cached = Cache::many(array_map(self::key(...), $publicationIds));
        $out = [];
        $missing = [];
        foreach ($publicationIds as $id) {
            $hit = $cached[self::key($id)] ?? null;
            $hit === null ? $missing[] = $id : $out[$id] = $hit;
        }
        if ($missing !== []) {
            foreach (DB::table('publications')->whereIn('id', $missing)->get(['id', 'html']) as $row) {
                $out[$row->id] = self::of($row->html);
                Cache::put(self::key($row->id), $out[$row->id], self::TTL);
            }
        }

        return $out;
    }

    private static function key(string $publicationId): string
    {
        return 'arkon:api:publication:v1:'.$publicationId;
    }

    /** @return array{html: string, css: string, seo: array} */
    public static function of(string $html): array
    {
        $start = strpos($html, '<main class="ak-main">');
        $end = strrpos($html, '</main>');
        if ($start !== false && $end !== false && $end > $start) {
            $main = substr($html, $start + strlen('<main class="ak-main">'), $end - $start - strlen('<main class="ak-main">'));
        } else {
            $open = strpos($html, '<body');
            $main = $open === false ? '' : preg_replace('#^<body[^>]*>|</body>\s*</html>\s*$#', '', substr($html, $open));
        }
        $headEnd = strpos($html, '</head>');
        $head = $headEnd === false ? '' : substr($html, 0, $headEnd);
        preg_match_all('#<style>(.*?)</style>#s', $head, $styles);

        return ['html' => trim((string) $main), 'css' => implode("\n", $styles[1]), 'seo' => self::seo($head)];
    }

    /** @return array{title: string, description: string, canonical: ?string, robots: array{index: bool, follow: bool}, open_graph: array, twitter: array} */
    private static function seo(string $head): array
    {
        $meta = [];
        preg_match_all('#<meta\s([^>]*)>#i', $head, $tags);
        foreach ($tags[1] as $attributes) {
            $a = self::attributes($attributes);
            $key = $a['name'] ?? $a['property'] ?? null;
            if ($key !== null && isset($a['content'])) {
                $meta[strtolower($key)] = $a['content'];
            }
        }
        $canonical = null;
        preg_match_all('#<link\s([^>]*)>#i', $head, $links);
        foreach ($links[1] as $attributes) {
            $a = self::attributes($attributes);
            if (strtolower($a['rel'] ?? '') === 'canonical') {
                $canonical = $a['href'] ?? null;
            }
        }
        $title = preg_match('#<title>(.*?)</title>#s', $head, $m) ? self::text($m[1]) : '';
        $robots = array_map('trim', explode(',', strtolower($meta['robots'] ?? '')));
        $prefixed = fn (string $prefix) => collect($meta)->filter(fn ($v, $k) => str_starts_with($k, $prefix))->mapWithKeys(fn ($v, $k) => [substr($k, strlen($prefix)) => $v])->all();

        return [
            'title' => $title,
            'description' => $meta['description'] ?? '',
            'canonical' => $canonical,
            'robots' => ['index' => ! in_array('noindex', $robots, true), 'follow' => ! in_array('nofollow', $robots, true)],
            'open_graph' => $prefixed('og:'),
            'twitter' => $prefixed('twitter:'),
        ];
    }

    private static function attributes(string $source): array
    {
        preg_match_all('#([a-zA-Z_:][-a-zA-Z0-9_:.]*)="([^"]*)"#', $source, $m, PREG_SET_ORDER);

        return collect($m)->mapWithKeys(fn ($a) => [strtolower($a[1]) => self::text($a[2])])->all();
    }

    private static function text(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}

<?php

namespace App\Http\Api\Resources;

use App\Arkon\Content\ContentTypes;
use App\Arkon\Content\Taxonomies;
use App\Arkon\Support\Time;
use Illuminate\Support\Facades\DB;

/**
 * A page, post or other content item in the API. The internal builder document is never
 * exposed: content is the rendered HTML of the page's main area (what visitors get, without the
 * site's shared header and footer) plus its stylesheet. Relations are embedded in compact form
 * and loaded in batches, so a page of results costs a fixed number of queries.
 */
final class ContentResource
{
    public function __construct(private readonly string $siteId, private readonly string $origin, private readonly bool $member) {}

    /**
     * @param  list<array>  $rows  ContentReader rows
     * @param  array<string, array{html: string, css: string, seo: array}|null>  $rendered  by id, for rows whose content or SEO is included
     * @return list<array>
     */
    public function many(array $rows, array $rendered = [], array $include = []): array
    {
        $media = MediaResource::byIds($this->siteId, array_column($rows, 'featuredMediaId'), $this->origin, $this->member);
        $termIds = array_merge([], ...array_column($rows, 'terms'));
        $terms = $termIds === [] ? collect() : DB::table('terms')->where('site_id', $this->siteId)->whereIn('id', array_unique($termIds))->orderBy('name')->get(['id', 'taxonomy', 'name', 'slug'])->keyBy('id');

        return array_map(fn ($row) => $this->present($row, $media, $terms->all(), $rendered[$row['id']] ?? null, $include), $rows);
    }

    private function present(array $row, array $media, array $terms, ?array $rendered, array $include): array
    {
        $type = ContentTypes::get($row['kind']);
        $out = [
            'id' => $row['id'],
            'type' => $row['kind'],
            'title' => $row['title'],
            'slug' => (string) preg_replace('#^.*/#', '', $row['path']),
            'path' => $row['path'],
            'link' => $row['livePath'] !== null ? $this->origin.$row['livePath'] : null,
            'status' => $row['status'],
        ];
        if ($type['details']) {
            $out['excerpt'] = $row['excerpt'];
            $out['featured_media'] = $row['featuredMediaId'] !== null ? ($media[$row['featuredMediaId']] ?? null) : null;
        }
        foreach ($type['taxonomies'] as $taxonomy) {
            $out[Taxonomies::get($taxonomy)['collection']] = array_values(array_map(
                fn ($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug],
                array_filter(array_map(fn ($id) => $terms[$id] ?? null, $row['terms']), fn ($t) => $t !== null && $t->taxonomy === $taxonomy),
            ));
        }
        $out['author'] = $row['authorId'] !== null ? ['id' => $row['authorId'], 'name' => (string) $row['authorName']] : null;
        $out['published_at'] = Time::iso($row['publishedAt']);
        $out['modified_at'] = Time::iso($row['modifiedAt']);
        if ($this->member && $row['version'] !== null) {
            $out['version'] = $row['version'];
            $out['has_unpublished_changes'] = $row['hasChanges'];
        }
        if (in_array('content', $include, true)) {
            $out['content'] = $rendered === null ? null : ['rendered' => $this->absolute($rendered['html']), 'css' => $this->absolute($rendered['css'])];
        }
        if (in_array('seo', $include, true)) {
            $out['seo'] = $rendered['seo'] ?? null;
        }

        return $out;
    }

    /** Image URLs in the HTML and CSS point at this site, so the content works on another origin. */
    private function absolute(string $markup): string
    {
        return (string) preg_replace('#(?<=["\s,(])/media/#', $this->origin.'/media/', $markup);
    }
}

<?php

namespace App\Arkon\Pages;

use Illuminate\Support\Facades\DB;

/**
 * Read-only public lookups. Deliberately no constructors or dependencies on
 * component catalogues, validators, renderers, drafts or editing services.
 */
final class PublicPages
{
    public function livePage(string $siteId, string $path): ?object
    {
        return DB::table('live_pages as l')
            ->join('publications as p', 'p.id', '=', 'l.publication_id')
            ->join('pages as pg', fn ($j) => $j->on('pg.site_id', '=', 'l.site_id')->on('pg.id', '=', 'l.page_id'))
            ->whereNull('pg.deleted_at')
            ->where('l.site_id', $siteId)
            ->where('l.path', $path)
            // The animation runtime the stored HTML loads, if any (its script policy allows exactly that file).
            ->first(['p.id as publication_id', 'p.html', DB::raw("p.render_inputs->>'motion' AS motion_runtime")]);
    }

    public function resolveRedirect(string $siteId, string $path): ?string
    {
        $target = DB::table('redirects as r')
            ->join('live_pages as l', fn ($j) => $j->on('l.site_id', '=', 'r.site_id')->on('l.page_id', '=', 'r.page_id'))
            ->join('pages as p', fn ($j) => $j->on('p.site_id', '=', 'r.site_id')->on('p.id', '=', 'r.page_id'))
            ->whereNull('p.deleted_at')
            ->where('r.site_id', $siteId)
            ->where('r.from_path', $path)
            ->value('l.path');

        return $target !== null && $target !== $path ? $target : null;
    }

    public function origin(string $siteId): ?string
    {
        $configured = rtrim((string) config('app.url'), '/');
        $host = parse_url($configured, PHP_URL_HOST);
        $port = parse_url($configured, PHP_URL_PORT);
        $hostname = $host ? $host.($port ? ':'.$port : '') : null;
        if ($hostname && DB::table('site_domains')->where('site_id', $siteId)->where('hostname', $hostname)->exists() && in_array(parse_url($configured, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return $configured;
        }
        $domain = DB::table('site_domains')->where('site_id', $siteId)->orderBy('hostname')->value('hostname');

        return $domain ? ((str_ends_with($domain, '.test') || str_starts_with($domain, '127.0.0.1') || str_starts_with($domain, 'localhost')) ? 'http://' : 'https://').$domain : null;
    }
}

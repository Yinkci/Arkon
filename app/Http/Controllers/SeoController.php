<?php

namespace App\Http\Controllers;

use App\Arkon\Pages\PublicPages;
use App\Arkon\Sites\Membership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SeoController extends Controller
{
    public function sitemap(Request $r, Membership $members)
    {
        $site = $members->siteForHost($r->getHttpHost());
        if (! $site) {
            abort(404);
        }$origin = app(PublicPages::class)->origin($site) ?? $r->getSchemeAndHttpHost();
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach (DB::table('live_pages as l')->join('publications as p', 'p.id', '=', 'l.publication_id')->join('page_revisions as v', 'v.id', '=', 'p.revision_id')->where('l.site_id', $site)->whereRaw("coalesce(v.document->'seo'->>'noindex', 'false') <> 'true'")->orderBy('l.path')->get(['l.path', 'p.created_at']) as $p) {
            $xml .= '<url><loc>'.htmlspecialchars($origin.$p->path, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc><lastmod>'.gmdate('c', strtotime($p->created_at)).'</lastmod></url>';
        }

        return response($xml.'</urlset>', 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function robots(Request $r, Membership $members)
    {
        $site = $members->siteForHost($r->getHttpHost());
        if (! $site) {
            abort(404);
        }

        return response("User-agent: *\nDisallow: /admin\nDisallow: /login\nDisallow: /preview\nDisallow: /_arkon/forms/\nSitemap: ".(app(PublicPages::class)->origin($site) ?? $r->getSchemeAndHttpHost())."/sitemap.xml\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }
}

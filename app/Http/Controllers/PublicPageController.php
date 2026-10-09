<?php

namespace App\Http\Controllers;

use App\Arkon\Pages\PublicPages;
use App\Arkon\Renderer\Motion;
use App\Arkon\Renderer\Widgets;
use App\Arkon\Sites\Membership;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public pages: stored publication HTML only. No rendering, no drafts, no
 * session, no React/Inertia/Vite. The admin is never involved.
 */
class PublicPageController extends Controller
{
    private const NOT_FOUND_HTML = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Page not found</title></head><body><main><h1>Page not found</h1></main></body></html>';

    /**
     * No scripts, except on a page with "when scrolled into view" animations: exactly the one
     * versioned animation runtime file of this origin (Motion), never inline or other scripts.
     */
    public static function csp(?string $runtime, string $origin, string $html = ''): string
    {
        $script = Motion::scriptSrc($runtime, $origin);
        $widgets = Widgets::scriptSrc($html, $origin);
        if ($widgets !== '') {
            $script = ($script === "'none'" ? '' : $script.' ').$widgets;
        }

        return "default-src 'self'; script-src ".$script."; style-src 'unsafe-inline'; img-src 'self' data:; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";
    }

    public function __invoke(Request $request, PublicPages $pages, Membership $membership, ?string $path = null): Response
    {
        $siteId = $membership->siteForHost($request->getHttpHost());
        if ($siteId === null) {
            return self::notFound();
        }
        $requested = '/'.($path ?? '');
        $page = $pages->livePage($siteId, $requested);
        if ($page === null) {
            // Old URL of a renamed page: permanent redirect to where the page is live now.
            // Not cached long by browsers, so a later rename back cannot leave a cached loop.
            $target = $pages->resolveRedirect($siteId, $requested);

            return $target === null
                ? self::notFound()
                : response('', 301, ['Location' => $target, 'Cache-Control' => 'public, max-age=0, must-revalidate']);
        }

        $etag = '"'.$page->publication_id.'"';
        $headers = [
            'Content-Type' => 'text/html; charset=utf-8',
            'ETag' => $etag,
            // Revalidate every request for now; cheap 304s. CDN caching with purge is planned.
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'Content-Security-Policy' => self::csp(Motion::loadedBy($page->motion_runtime, $page->html), $request->getSchemeAndHttpHost(), $page->html),
            'X-Content-Type-Options' => 'nosniff',
        ];
        $response = response($page->html, 200, $headers);
        // Symfony implements weak validators, validator lists and wildcard matching.
        // Always resolve live state first, so deleted/unpublished pages never return 304.
        $response->isNotModified($request);

        return $response;
    }

    private static function notFound(): Response
    {
        return response(self::NOT_FOUND_HTML, 404, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}

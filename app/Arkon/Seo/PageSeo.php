<?php

namespace App\Arkon\Seo;

use App\Arkon\Pages\PageStore;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Sites\SiteContext;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use Illuminate\Support\Facades\DB;

/**
 * The one way to get a page's SEO report: the deterministic SeoAnalysis of its HTML as rendered
 * by the production renderer, with the site's known URLs. The editor, the API and AI actions all
 * use it; AI may suggest fixes but never produces a score.
 */
final class PageSeo
{
    public function __construct(private readonly PageStore $store, private readonly SeoAnalysis $analysis, private readonly Authorizer $auth) {}

    /** A document (an editor's unsaved one, or the saved draft) analyzed as it would be published. */
    public function analyzeDocument(SiteContext $ctx, string $pageId, mixed $document): array
    {
        $this->auth->authorize($ctx, 'page.view');
        $page = $this->store->loadPage($ctx->siteId, Input::id($pageId));
        $out = $this->store->renderForSite($ctx->siteId, $page->title, $page->path, $document, false);

        return $this->analysis->analyze($out['html'], Json::entries($document['seo'] ?? []), self::knownPaths($ctx->siteId, $page->path), $this->store->origin($ctx->siteId) ?? '');
    }

    public function analyzeDraft(SiteContext $ctx, string $pageId): array
    {
        $this->auth->authorize($ctx, 'page.view');
        $draft = $this->store->loadDraft($ctx->siteId, Input::id($pageId));

        return $this->analyzeDocument($ctx, $pageId, $this->store->document($draft->document));
    }

    /** What visitors are served now, or null when the page is not live. */
    public function analyzeLive(string $siteId, string $pageId): ?array
    {
        $live = $this->store->loadLive($siteId, $pageId);
        if ($live === null) {
            return null;
        }
        $html = DB::table('publications')->where('id', $live->publication_id)->value('html');
        $seo = Json::entries(Json::decode(DB::table('page_revisions')->where('id', $live->revision_id)->value('document'))['seo'] ?? []);

        return $this->analysis->analyze($html, $seo, self::knownPaths($siteId, $live->path), $this->store->origin($siteId) ?? '');
    }

    /** Live URLs and the old URLs that redirect to live pages: internal links to them are fine. */
    public static function knownPaths(string $siteId, string $self): array
    {
        $paths = DB::table('live_pages')->where('site_id', $siteId)->pluck('path')->all();
        $redirects = DB::table('redirects as r')->join('live_pages as l', fn ($j) => $j->on('r.site_id', '=', 'l.site_id')->on('r.page_id', '=', 'l.page_id'))->where('r.site_id', $siteId)->pluck('r.from_path')->all();

        return [...$paths, ...$redirects, $self];
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Media\MediaLibrary;
use App\Arkon\Pages\PublicPages;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Support\Json;
use App\Http\AdminContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class SiteOverviewController extends Controller
{
    private function identity(string $siteId): array
    {
        $row = DB::table('sites')->where('id', $siteId)->first(['name', 'settings']);
        $settings = Json::entries(Json::decode($row->settings));

        return ['name' => $row->name, 'language' => $settings['lang'] ?? 'en'];
    }

    public function media(Request $r, MediaLibrary $media)
    {
        $input = $r->validate(['q' => 'nullable|string|max:120', 'sort' => 'nullable|in:newest,oldest,name,largest,smallest', 'page' => 'nullable|integer|min:1|max:100000']);

        return Inertia::render('Admin/Media', ['library' => $media->browse(AdminContext::of($r)->ctx(), trim($input['q'] ?? ''), $input['sort'] ?? 'newest', (int) ($input['page'] ?? 1))]);
    }

    public function search(Request $r, Authorizer $auth)
    {
        $ctx = AdminContext::of($r)->ctx();
        $auth->authorize($ctx, 'page.view');
        $input = $r->validate(['q' => 'nullable|string|max:120']);
        $q = trim($input['q'] ?? '');
        // strpos is literal: '%' and '_' in search text are not wildcard queries.
        $pages = $q === '' ? [] : DB::table('pages')->where('site_id', $ctx->siteId)->whereNull('deleted_at')->whereRaw('(strpos(lower(title), lower(?)) > 0 OR strpos(lower(path), lower(?)) > 0)', [$q, $q])->orderBy('title')->limit(10)->get(['id', 'title', 'path'])->all();

        return response()->json(['pages' => $pages]);
    }

    public function settings(Request $r, Authorizer $auth, PublicPages $public)
    {
        $ctx = AdminContext::of($r)->ctx();
        $auth->authorize($ctx, 'page.publish');

        return Inertia::render('Admin/SiteOverview', ['section' => 'settings', 'identity' => $this->identity($ctx->siteId), 'origin' => $public->origin($ctx->siteId), 'domains' => DB::table('site_domains')->where('site_id', $ctx->siteId)->orderBy('hostname')->pluck('hostname')->all()]);
    }

    public function seo(Request $r, Authorizer $auth)
    {
        $ctx = AdminContext::of($r)->ctx();
        $auth->authorize($ctx, 'page.view');
        $page = (int) max(1, min(100000, (int) $r->query('page', 1)));
        $query = DB::table('pages as p')->join('page_drafts as d', 'd.page_id', '=', 'p.id')->leftJoin('live_pages as l', 'l.page_id', '=', 'p.id')->leftJoin('publications as pub', 'pub.id', '=', 'l.publication_id')->leftJoin('page_revisions as rev', 'rev.id', '=', 'pub.revision_id')->where('p.site_id', $ctx->siteId)->whereNull('p.deleted_at');
        $total = (clone $query)->count();
        $rows = $query->orderBy('p.title')->offset(($page - 1) * 50)->limit(50)->get(['p.id', 'p.title', 'p.path', 'd.document', 'rev.document as live_document', 'l.path as live_path'])->map(function ($p) {
            $draft = Json::entries(Json::decode($p->document)['seo']);
            $live = $p->live_document ? Json::entries(Json::decode($p->live_document)['seo']) : null;

            return ['id' => $p->id, 'title' => $p->title, 'path' => $p->path, 'livePath' => $p->live_path, 'description' => $draft['description'] ?? '', 'liveDescription' => $live['description'] ?? null, 'noindex' => $draft['noindex'] ?? false, 'liveNoindex' => $p->live_path ? (bool) ($live['noindex'] ?? false) : null];
        })->all();

        return Inertia::render('Admin/SiteOverview', ['section' => 'seo', 'rows' => $rows, 'page' => $page, 'total' => $total]);
    }
}

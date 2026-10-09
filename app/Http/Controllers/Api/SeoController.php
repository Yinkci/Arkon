<?php

namespace App\Http\Controllers\Api;

use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Pages\PageStore;
use App\Arkon\Seo\SeoAnalysis;
use App\Arkon\Seo\SeoDefaults;
use App\Arkon\Sites\Authorizer;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;
use App\Http\AdminContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class SeoController
{
    public function defaults(Request $r, SeoDefaults $settings)
    {
        return Inertia::render('Admin/SeoDefaults', ['settings' => $settings->state(AdminContext::of($r)->ctx()), 'media' => app(MediaService::class)->list(AdminContext::of($r)->ctx())]);
    }

    public function save(Request $r, SeoDefaults $settings)
    {
        return EditorApiController::ok($settings->save(AdminContext::of($r)->ctx(), EditorApiController::body($r)));
    }

    public function publish(Request $r, SeoDefaults $settings)
    {
        return EditorApiController::ok($settings->publish(AdminContext::of($r)->ctx(), EditorApiController::body($r)));
    }

    public function analyze(Request $request, string $page, Authorizer $auth, PageStore $store, SeoAnalysis $analysis)
    {
        $ctx = AdminContext::of($request)->ctx();
        $auth->authorize($ctx, 'page.view');
        $row = $store->loadPage($ctx->siteId, Input::id($page));
        $body = EditorApiController::body($request);
        $out = $store->renderForSite($ctx->siteId, $row->title, $row->path, $body['document'] ?? null, false);
        $paths = DB::table('live_pages')->where('site_id', $ctx->siteId)->pluck('path')->all();
        $paths = [...$paths, ...DB::table('redirects as r')->join('live_pages as l', fn ($j) => $j->on('r.site_id', '=', 'l.site_id')->on('r.page_id', '=', 'l.page_id'))->where('r.site_id', $ctx->siteId)->pluck('r.from_path')->all(), $row->path];

        $report = $analysis->analyze($out['html'], Json::entries($body['document']['seo'] ?? []), $paths, $store->origin($ctx->siteId) ?? '');
        // Preview capability is transient and site-authorized; it is never part of public metadata or stored score.
        $report['socialPreviewUrl'] = $report['socialImage'] !== '' ? app(MediaSigner::class)->signUrl($report['socialImage']) : '';

        return EditorApiController::ok($report);
    }
}

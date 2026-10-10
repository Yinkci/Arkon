<?php

namespace App\Http\Controllers\Api;

use App\Arkon\Media\MediaService;
use App\Arkon\Media\MediaSigner;
use App\Arkon\Seo\PageSeo;
use App\Arkon\Seo\SeoDefaults;
use App\Http\AdminContext;
use Illuminate\Http\Request;
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

    public function analyze(Request $request, string $page, PageSeo $seo)
    {
        $ctx = AdminContext::of($request)->ctx();
        $report = $seo->analyzeDocument($ctx, $page, EditorApiController::body($request)['document'] ?? null);
        // Preview capability is transient and site-authorized; it is never part of public metadata or stored score.
        $report['socialPreviewUrl'] = $report['socialImage'] !== '' ? app(MediaSigner::class)->signUrl($report['socialImage']) : '';

        return EditorApiController::ok($report);
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Content\SiteDataService;
use App\Http\AdminContext;
use App\Http\Controllers\Api\EditorApiController;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The screen and endpoints for site data. HTTP only: SiteDataService holds the rules, so the
 * API and AI reach the same behaviour through the same service.
 */
final class SiteDataController extends Controller
{
    public function index(Request $request, SiteDataService $data): Response
    {
        $admin = AdminContext::of($request);

        return Inertia::render('Admin/SiteData', [
            'data' => $data->show($admin->ctx()),
            'history' => $data->history($admin->ctx()),
            'canEdit' => $admin->can('page.publish'),
        ]);
    }

    public function update(Request $request, SiteDataService $data): JsonResponse
    {
        $body = EditorApiController::body($request);
        $version = $body['expectedVersion'] ?? null;

        return EditorApiController::ok($data->update(
            AdminContext::of($request)->ctx(),
            (array) ($body['values'] ?? []),
            is_int($version) ? $version : null,
        ));
    }

    public function restore(Request $request, SiteDataService $data): JsonResponse
    {
        $body = EditorApiController::body($request);

        return EditorApiController::ok($data->restore(AdminContext::of($request)->ctx(), (string) ($body['id'] ?? '')));
    }
}

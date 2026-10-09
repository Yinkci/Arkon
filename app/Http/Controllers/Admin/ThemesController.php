<?php

namespace App\Http\Controllers\Admin;

use App\Arkon\Sites\Authorizer;
use App\Arkon\Themes\ThemeCatalogue;
use App\Arkon\Themes\ThemeService;
use App\Http\AdminContext;
use App\Http\Controllers\Api\EditorApiController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ThemesController extends Controller
{
    public function index(Request $request, ThemeService $themes)
    {
        $admin = AdminContext::of($request);

        return Inertia::render('Admin/Themes', ['init' => $themes->state($admin->ctx()), 'manage' => $admin->can('page.publish')]);
    }

    public function state(Request $request, ThemeService $themes)
    {
        return EditorApiController::ok($themes->state(AdminContext::of($request)->ctx()));
    }

    public function activate(Request $request, ThemeService $themes)
    {
        return EditorApiController::ok($themes->activate(AdminContext::of($request)->ctx(), EditorApiController::body($request)));
    }

    public function publish(Request $request, ThemeService $themes)
    {
        return EditorApiController::ok($themes->publish(AdminContext::of($request)->ctx(), EditorApiController::body($request)));
    }

    public function preview(Request $request, string $theme, Authorizer $auth)
    {
        $auth->authorize(AdminContext::of($request)->ctx(), 'page.view');
        try {
            $html = ThemeCatalogue::preview($theme);
        } catch (\Throwable) {
            abort(422, 'This theme is not ready to preview. Validate its files first.');
        }

        return response($html)->header('Content-Type', 'text/html; charset=UTF-8')->header('Cache-Control', 'private, no-store')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; script-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
    }
}

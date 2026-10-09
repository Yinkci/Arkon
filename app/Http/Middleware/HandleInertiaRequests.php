<?php

namespace App\Http\Middleware;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Pages\PublicPages;
use App\Arkon\Themes\ThemeService;
use App\Http\AdminContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        // Resolved by ResolveAdminSite on admin routes (runs before the page renders).
        $admin = fn () => $request->attributes->get(AdminContext::class);

        return [
            ...parent::share($request),
            'auth' => fn () => $request->user()
                ? ['user' => ['id' => $request->user()->id, 'name' => $request->user()->name, 'email' => $request->user()->email]]
                : ['user' => null],
            'site' => fn () => $admin() ? ['id' => $admin()->siteId, 'name' => $admin()->siteName, 'role' => $admin()->role, 'url' => app(PublicPages::class)->origin($admin()->siteId)] : null,
            'themeAddableTypes' => fn () => $admin() ? ThemeService::availableTypes($admin()->siteId) : [],
            'themeComponents' => fn () => $request->user() ? app(ComponentRegistry::class)->themeManifests() : [],
            'siteMenus' => fn () => $admin() ? DB::table('site_menus')->where('site_id', $admin()->siteId)->get(['id', 'name', 'published_version'])->all() : [],
            'siteForms' => fn () => $admin() ? DB::table('site_forms')->where('site_id', $admin()->siteId)->get(['id', 'name', 'published_version'])->all() : [],
            'can' => fn () => $admin()?->permissions() ?? [],
        ];
    }
}

<?php

namespace App\Http\Middleware;

use App\Http\AdminContext;
use Illuminate\Http\Request;
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
            'site' => fn () => $admin() ? ['id' => $admin()->siteId, 'name' => $admin()->siteName, 'role' => $admin()->role] : null,
            'can' => fn () => $admin()?->permissions() ?? [],
        ];
    }
}

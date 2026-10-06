<?php

namespace App\Http\Middleware;

use App\Arkon\Sites\Membership;
use App\Http\AdminContext;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Requires a signed-in user who is a member of at least one site. */
class ResolveAdminSite
{
    public function __construct(private readonly Membership $membership) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $site = $user ? ($this->membership->sitesOf($user->id)[0] ?? null) : null;
        if ($site === null) {
            if ($request->is('admin/api/*')) {
                return response()->json(['ok' => false, 'code' => 'FORBIDDEN', 'message' => 'Your account is not a member of any site.'], 403);
            }

            return Inertia::render('Auth/NoSite')->toResponse($request)->setStatusCode(403);
        }
        $request->attributes->set(AdminContext::class, new AdminContext($user, $site->site_id, $site->site_name, $site->role));

        return $next($request);
    }
}

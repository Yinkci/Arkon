<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline headers for the signed-in surface (admin, editor, previews, sign-in). Other sites
 * cannot frame it (clickjacking of Publish, Delete and similar actions); the editor's own
 * frames are same-origin. Responses that set their own policy (previews) keep it.
 */
class AdminSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        foreach (['X-Frame-Options' => 'SAMEORIGIN', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'same-origin'] as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}

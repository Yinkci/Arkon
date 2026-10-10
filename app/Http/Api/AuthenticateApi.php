<?php

namespace App\Http\Api;

use App\Arkon\Api\ApiTokens;
use App\Arkon\Sites\Membership;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the site from the request's host (an API serves the site it is reached at, like its
 * pages) and, when an Authorization header is sent, the token. A token is only valid for its own
 * site, so a Site A token can never read or change Site B. No session or cookie is ever used.
 */
final class AuthenticateApi
{
    public function __construct(private readonly Membership $membership, private readonly ApiTokens $tokens) {}

    public function handle(Request $request, Closure $next): Response
    {
        $siteId = $this->membership->siteForHost($request->getHttpHost());
        if ($siteId === null) {
            throw new ApiError(404, 'site_not_found', 'No Arkon site is served at this address.');
        }
        $principal = new ApiPrincipal($siteId);
        $header = $request->headers->get('Authorization');
        if ($header !== null) {
            if (! preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
                throw ApiError::invalidToken();
            }
            $row = $this->tokens->resolve($m[1], $siteId) ?? throw ApiError::invalidToken();
            $principal = new ApiPrincipal($siteId, $row->user_id, ApiTokens::scopesOf($row), $row->id);
        }
        $request->attributes->set(ApiPrincipal::class, $principal);

        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Authorization'])));
        if ($principal->authenticated() || ! $request->isMethodSafe()) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}

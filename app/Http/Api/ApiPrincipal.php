<?php

namespace App\Http\Api;

use App\Arkon\Sites\SiteContext;
use Illuminate\Http\Request;

/**
 * Who an API request acts for: always one site (from the request's host), and with a token one
 * member of it, limited to the token's scopes. Services authorize the member's role again, so a
 * scope never grants more than the role allows.
 */
final class ApiPrincipal
{
    /** @param list<string> $scopes */
    public function __construct(public readonly string $siteId, public readonly ?string $userId = null, public readonly array $scopes = [], public readonly ?string $tokenId = null) {}

    public static function of(Request $request): self
    {
        return $request->attributes->get(self::class) ?? throw new \LogicException('The API principal is resolved by AuthenticateApi.');
    }

    public function authenticated(): bool
    {
        return $this->userId !== null;
    }

    public function has(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** The member's context for a request that needs this scope: 401 without a token, 403 without the scope. */
    public function require(string $scope): SiteContext
    {
        if (! $this->authenticated()) {
            throw ApiError::unauthenticated();
        }
        if (! $this->has($scope)) {
            throw ApiError::insufficientScope($scope);
        }

        return new SiteContext($this->siteId, $this->userId, 'api');
    }
}

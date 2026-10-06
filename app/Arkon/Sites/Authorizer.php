<?php

namespace App\Arkon\Sites;

use App\Arkon\Errors\ForbiddenException;
use App\Arkon\Errors\NotFoundException;

/**
 * The single choke point every service calls first. Runs on the service's own
 * connection (inside its transaction).
 */
class Authorizer
{
    public function __construct(private readonly Membership $membership) {}

    /** @return string the actor's role on ctx.siteId */
    public function authorize(SiteContext $ctx, string $permission): string
    {
        $role = $this->membership->roleOf($ctx->siteId, $ctx->userId);
        // Non-members get "not found", not "forbidden": they learn nothing about the site.
        if ($role === null) {
            throw new NotFoundException('Site');
        }
        if (! Permissions::allows($role, $permission)) {
            throw new ForbiddenException($permission);
        }

        return $role;
    }
}

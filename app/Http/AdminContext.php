<?php

namespace App\Http;

use App\Arkon\Sites\Permissions;
use App\Arkon\Sites\SiteContext;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The signed-in user and the site they are working on. Until a site switcher
 * exists, the admin works on the user's first site. Permissions here are UI
 * hints only: every service call authorizes again.
 */
final class AdminContext
{
    public function __construct(
        public readonly User $user,
        public readonly string $siteId,
        public readonly string $siteName,
        public readonly string $role,
    ) {}

    public static function of(Request $request): self
    {
        return $request->attributes->get(self::class);
    }

    public function ctx(): SiteContext
    {
        return new SiteContext($this->siteId, $this->user->id, 'ui');
    }

    public function can(string $permission): bool
    {
        return Permissions::allows($this->role, $permission);
    }

    /** @return array<string, bool> */
    public function permissions(): array
    {
        return array_combine(Permissions::ALL, array_map($this->can(...), Permissions::ALL));
    }
}

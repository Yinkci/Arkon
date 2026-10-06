<?php

namespace App\Arkon\Sites;

/** Permissions are code, roles are data. */
final class Permissions
{
    public const ALL = [
        'page.view',
        'page.create',
        'page.edit',
        'page.publish',
        'page.delete',
        'media.view',
        'media.upload',
    ];

    public const ROLES = ['owner', 'admin', 'editor', 'viewer'];

    private const ROLE_PERMISSIONS = [
        'owner' => self::ALL,
        'admin' => self::ALL,
        // Editors draft (including new pages, titles and URLs) but cannot change what is live.
        'editor' => ['page.view', 'page.create', 'page.edit', 'media.view', 'media.upload'],
        'viewer' => ['page.view', 'media.view'],
    ];

    public static function allows(?string $role, string $permission): bool
    {
        return $role !== null && in_array($permission, self::ROLE_PERMISSIONS[$role] ?? [], true);
    }

    /** @return list<string> */
    public static function of(?string $role): array
    {
        return $role === null ? [] : self::ROLE_PERMISSIONS[$role] ?? [];
    }
}

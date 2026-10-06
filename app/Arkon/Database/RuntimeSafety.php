<?php

namespace App\Arkon\Database;

use Illuminate\Database\ConnectionInterface;

/**
 * The web app runs with the least privilege it needs. It refuses to serve when
 * privileged credentials are in its environment or when its database role could
 * change the schema (see ARCHITECTURE.md, "Database roles").
 */
class RuntimeSafety
{
    /** Variables that grant more than the runtime needs. Their presence is a deployment mistake. */
    public const PRIVILEGED_ENV_VARS = ['MIGRATION_DB_USERNAME', 'MIGRATION_DB_PASSWORD', 'PG_SUPERUSER_URL', 'PG_SUPERUSER_PASSWORD'];

    /**
     * @param  array<string, mixed>  $env
     *
     * @throws ConfigurationException
     */
    public static function validateEnvironment(array $env, ?string $appKey): void
    {
        $problems = [];
        foreach (self::PRIVILEGED_ENV_VARS as $name) {
            if (($env[$name] ?? '') !== '' && ($env[$name] ?? false) !== false) {
                $problems[] = "{$name} must not be available to the running app (keep it in .migrate.env or the shell of the one command that needs it)";
            }
        }
        if ($appKey === null || strlen($appKey) < 32) {
            $problems[] = 'APP_KEY must be set (php artisan key:generate)';
        }
        if ($problems !== []) {
            throw new ConfigurationException($problems);
        }
    }

    /** The process environment as PHP sees it (getenv, $_ENV and $_SERVER). */
    public static function processEnvironment(): array
    {
        $env = [];
        foreach (self::PRIVILEGED_ENV_VARS as $name) {
            $value = getenv($name);
            $env[$name] = $value !== false ? $value : ($_ENV[$name] ?? $_SERVER[$name] ?? '');
        }

        return $env;
    }

    /**
     * Checks what the connected role can actually do: not a superuser, owns no
     * tables, cannot create objects in schema public.
     *
     * @throws ConfigurationException
     */
    public static function assertRestrictedRole(ConnectionInterface $db): void
    {
        $row = $db->selectOne(<<<'SQL'
            select current_user as role,
                   (select rolsuper from pg_roles where rolname = current_user) as superuser,
                   (select count(*)::int from pg_tables where schemaname = 'public' and tableowner = current_user) as owned,
                   has_schema_privilege(current_user, 'public', 'CREATE') as can_create
            SQL);
        $problems = [];
        if ($row->superuser) {
            $problems[] = "database role {$row->role} is a superuser";
        }
        if ($row->owned > 0) {
            $problems[] = "database role {$row->role} owns {$row->owned} tables (use the restricted runtime role)";
        }
        if ($row->can_create) {
            $problems[] = "database role {$row->role} can create objects in schema public";
        }
        if ($problems !== []) {
            throw new ConfigurationException($problems);
        }
    }
}

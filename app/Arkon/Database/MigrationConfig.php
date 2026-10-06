<?php

namespace App\Arkon\Database;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Schema-owner access for migration tooling and test resets.
 *
 * The credentials live in `.migrate.env`, which Laravel never loads. They are
 * parsed here and passed straight into a connection config: they are never put
 * into the process environment, so nothing started from this process (for
 * example a test web server) can inherit them.
 */
class MigrationConfig
{
    public const CONNECTION = 'arkon_owner';

    public const TARGETS = ['dev', 'test', 'e2e'];

    /** @return array{username: string, password: string} */
    public static function ownerCredentials(): array
    {
        $file = base_path(config('arkon.migration_env_file'));
        $values = is_file($file) ? Dotenv::parse((string) file_get_contents($file)) : [];
        $username = $values['MIGRATION_DB_USERNAME'] ?? '';
        $password = $values['MIGRATION_DB_PASSWORD'] ?? '';
        if ($username === '' || $password === '') {
            throw new ConfigurationException([
                'MIGRATION_DB_USERNAME and MIGRATION_DB_PASSWORD must be set in .migrate.env (copy .migrate.env.example)',
            ]);
        }

        return ['username' => $username, 'password' => $password];
    }

    public static function databaseFor(string $target): string
    {
        if (! in_array($target, self::TARGETS, true)) {
            throw new InvalidArgumentException("Unknown target {$target}");
        }

        return config("arkon.databases.{$target}");
    }

    /**
     * Both roles on the same server, different roles, and the owner is not the
     * superuser.
     *
     * @throws ConfigurationException
     */
    public static function validate(string $appRole, string $ownerRole): void
    {
        $problems = [];
        if ($appRole === '' || $ownerRole === '') {
            $problems[] = 'DB_USERNAME and MIGRATION_DB_USERNAME must both be set';
        } elseif (strcasecmp($appRole, $ownerRole) === 0) {
            $problems[] = 'DB_USERNAME and MIGRATION_DB_USERNAME must use different roles';
        }
        if (strcasecmp($ownerRole, 'postgres') === 0) {
            $problems[] = 'MIGRATION_DB_USERNAME must be the schema-owner role, not the superuser';
        }
        if ($problems !== []) {
            throw new ConfigurationException($problems);
        }
    }

    /** Registers (or re-points) the owner connection at the target database and returns its name. */
    public static function connect(string $target): string
    {
        $credentials = self::ownerCredentials();
        $base = config('database.connections.pgsql');
        self::validate((string) $base['username'], $credentials['username']);

        DB::purge(self::CONNECTION);
        config(['database.connections.'.self::CONNECTION => array_merge($base, [
            'url' => null,
            'database' => self::databaseFor($target),
            'username' => $credentials['username'],
            'password' => $credentials['password'],
        ])]);

        return self::CONNECTION;
    }

    public static function disconnect(): void
    {
        DB::purge(self::CONNECTION);
        config(['database.connections.'.self::CONNECTION => null]);
    }
}

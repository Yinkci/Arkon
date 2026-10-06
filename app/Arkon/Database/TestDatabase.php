<?php

namespace App\Arkon\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Test-database maintenance for PHPUnit and Playwright. Truncation needs the
 * schema owner: the runtime role is deliberately not allowed to do it. Refuses
 * to touch the dev database.
 */
final class TestDatabase
{
    public static function migrate(string $target): void
    {
        self::guard($target);
        app(SchemaMigrator::class)->migrate($target);
    }

    public static function truncate(string $target): void
    {
        self::guard($target);
        $connection = MigrationConfig::connect($target);
        try {
            $db = DB::connection($connection);
            $tables = collect($db->select("select tablename from pg_tables where schemaname = 'public'"))
                ->pluck('tablename')
                ->reject(fn ($t) => in_array($t, ['migrations', 'data_upgrades'], true))
                ->map(fn ($t) => '"'.$t.'"')
                ->implode(', ');
            if ($tables !== '') {
                $db->statement("TRUNCATE {$tables} CASCADE");
            }
        } finally {
            MigrationConfig::disconnect();
        }
    }

    private static function guard(string $target): void
    {
        if (! in_array($target, ['test', 'e2e'], true)) {
            throw new InvalidArgumentException('Only the test and e2e databases can be reset');
        }
        if (MigrationConfig::databaseFor($target) === MigrationConfig::databaseFor('dev')) {
            throw new InvalidArgumentException('The test database must differ from the dev database');
        }
    }
}

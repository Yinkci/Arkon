<?php

namespace App\Arkon\Database;

use App\Arkon\Upgrades\DataUpgrades;
use Closure;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies the schema as the owner role: Laravel migrations, then the runtime
 * grants, then pending data upgrades. Used by `arkon:migrate` and the test harness.
 */
class SchemaMigrator
{
    /** History tables: the runtime role may read and insert, never update or delete. */
    public const APPEND_ONLY_TABLES = ['page_revisions', 'publications', 'publication_media', 'audit_logs', 'site_token_versions', 'reusable_component_versions', 'publication_dependencies', 'media_variants', 'publication_render_compat', 'site_theme_versions', 'site_theme_requests'];

    /** Tables the runtime role must not touch at all. */
    public const MIGRATION_ONLY_TABLES = ['data_upgrades', 'migrations'];

    public function __construct(private readonly Migrator $migrator, private readonly DataUpgrades $upgrades) {}

    /**
     * @param  list<string>|null  $paths  Only these migration files (upgrade tests stop at an older schema).
     * @param  Closure(string): void  $log
     */
    public function migrate(string $target, ?array $paths = null, bool $runUpgrades = true, ?Closure $log = null): void
    {
        $log ??= fn (string $message) => null;
        $connection = MigrationConfig::connect($target);
        $appRole = (string) config('database.connections.pgsql.username');
        app()->instance('arkon.migrating', true);
        $previousDefault = DB::getDefaultConnection();
        try {
            $db = DB::connection($connection);
            $super = $db->selectOne('select rolsuper from pg_roles where rolname = current_user');
            if ($super?->rolsuper) {
                throw new RuntimeException('Refusing to migrate as a superuser: objects must be owned by the schema-owner role');
            }

            $this->migrator->usingConnection($connection, function () use ($paths, $log) {
                if (! $this->migrator->repositoryExists()) {
                    $this->migrator->getRepository()->createRepository();
                }
                $ran = $this->migrator->run($paths ?? [database_path('migrations')]);
                foreach ($ran as $file) {
                    $log('Migrated '.basename((string) $file, '.php'));
                }
            });

            $this->grant($connection, $appRole);

            if ($runUpgrades) {
                DB::setDefaultConnection($connection);
                $this->upgrades->runPending($log);
            }
        } finally {
            DB::setDefaultConnection($previousDefault);
            app()->forgetInstance('arkon.migrating');
            MigrationConfig::disconnect();
        }
    }

    /** Idempotent; runs after every migrate so new tables are always covered. */
    public function grant(string $connection, string $appRole): void
    {
        $db = DB::connection($connection);
        $existing = collect($db->select("select tablename from pg_tables where schemaname = 'public'"))
            ->pluck('tablename')->all();
        $quote = fn (string $identifier) => '"'.str_replace('"', '""', $identifier).'"';
        $list = fn (array $tables) => implode(', ', array_map($quote, array_values(array_intersect($tables, $existing))));
        $role = $quote($appRole);

        $statements = [
            "GRANT USAGE ON SCHEMA public TO {$role}",
            "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}",
            "GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO {$role}",
        ];
        if ($appendOnly = $list(self::APPEND_ONLY_TABLES)) {
            $statements[] = "REVOKE UPDATE, DELETE, TRUNCATE ON {$appendOnly} FROM {$role}";
        }
        if ($migrationOnly = $list(self::MIGRATION_ONLY_TABLES)) {
            $statements[] = "REVOKE ALL ON {$migrationOnly} FROM {$role}";
        }
        foreach ($statements as $sql) {
            $db->statement($sql);
        }
    }
}

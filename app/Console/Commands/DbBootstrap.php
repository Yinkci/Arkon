<?php

namespace App\Console\Commands;

use App\Arkon\Database\MigrationConfig;
use Illuminate\Console\Command;
use PDO;
use PDOException;

/**
 * One-time setup per machine, with superuser credentials supplied only for this
 * command (never stored):
 *
 *   PowerShell:  $env:PG_SUPERUSER_PASSWORD = Read-Host "postgres password" -MaskInput
 *                php artisan arkon:db-bootstrap
 *                Remove-Item Env:PG_SUPERUSER_PASSWORD
 *
 * (PG_SUPERUSER defaults to "postgres"; host and port come from .env. A
 * percent-encoded PG_SUPERUSER_URL works too.)
 *
 * Creates the schema-owner role, the restricted runtime role and the dev, test
 * and e2e databases. Safe to re-run: existing roles get their passwords re-synced
 * from .env / .migrate.env. Roles and databases of other projects are untouched.
 */
class DbBootstrap extends Command
{
    protected $signature = 'arkon:db-bootstrap';

    protected $description = 'Create the Arkon database roles and databases (needs PG_SUPERUSER_URL for this one command)';

    public function handle(): int
    {
        $env = (string) @file_get_contents(base_path('.env'));
        if (str_contains($env, 'PG_SUPERUSER')) {
            $this->error('Remove PG_SUPERUSER_* from .env: superuser credentials must never be stored.');

            return self::FAILURE;
        }
        // Either a plain password (no escaping needed) or a URL whose parts are percent-encoded.
        $password = getenv('PG_SUPERUSER_PASSWORD');
        $url = getenv('PG_SUPERUSER_URL') ?: '';
        if ($password !== false && $password !== '') {
            $parts = [
                'host' => config('database.connections.pgsql.host'),
                'port' => (int) config('database.connections.pgsql.port'),
                'user' => getenv('PG_SUPERUSER') ?: 'postgres',
                'pass' => $password,
                'path' => '/postgres',
            ];
        } elseif ($url !== '') {
            $parts = parse_url($url) ?: [];
            if (! in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true) || empty($parts['user'])) {
                $this->error('PG_SUPERUSER_URL must look like postgres://postgres:<percent-encoded password>@127.0.0.1:5432/postgres');

                return self::FAILURE;
            }
            $parts['user'] = rawurldecode($parts['user']);
            $parts['pass'] = rawurldecode($parts['pass'] ?? '');
        } else {
            $this->error('Set PG_SUPERUSER_PASSWORD (or PG_SUPERUSER_URL) in the shell for this command only. It is never stored.');

            return self::FAILURE;
        }

        $owner = MigrationConfig::ownerCredentials();
        $app = ['username' => (string) config('database.connections.pgsql.username'), 'password' => (string) config('database.connections.pgsql.password')];
        MigrationConfig::validate($app['username'], $owner['username']);
        if ($app['password'] === '' || str_contains($app['password'], 'CHANGE_ME') || str_contains($owner['password'], 'CHANGE_ME')) {
            $this->error('Set DB_PASSWORD in .env and MIGRATION_DB_PASSWORD in .migrate.env to strong random values first.');

            return self::FAILURE;
        }

        try {
            $pdo = new PDO(
                sprintf('pgsql:host=%s;port=%d;dbname=%s', $parts['host'] ?? '127.0.0.1', $parts['port'] ?? 5432, ltrim($parts['path'] ?? '/postgres', '/') ?: 'postgres'),
                $parts['user'],
                $parts['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $error) {
            $this->error("Could not connect as {$parts['user']}: ".$error->getMessage());

            return self::FAILURE;
        }
        $id = fn (string $name) => '"'.str_replace('"', '""', $name).'"';

        foreach ([$owner, $app] as $role) {
            $exists = $pdo->prepare('select 1 from pg_roles where rolname = ?');
            $exists->execute([$role['username']]);
            $verb = $exists->fetchColumn() ? 'ALTER' : 'CREATE';
            $pdo->exec("{$verb} ROLE {$id($role['username'])} LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD ".$pdo->quote($role['password']));
            $this->line(($verb === 'CREATE' ? 'Created' : 'Updated')." role {$role['username']}");
        }

        foreach (MigrationConfig::TARGETS as $target) {
            $name = MigrationConfig::databaseFor($target);
            $exists = $pdo->prepare('select 1 from pg_database where datname = ?');
            $exists->execute([$name]);
            if ($exists->fetchColumn()) {
                $this->line("Database {$name} already exists");
            } else {
                $pdo->exec("CREATE DATABASE {$id($name)} OWNER {$id($owner['username'])} ENCODING 'UTF8'");
                $this->line("Created database {$name}");
            }
            $pdo->exec("REVOKE ALL ON DATABASE {$id($name)} FROM PUBLIC");
            $pdo->exec("GRANT CONNECT ON DATABASE {$id($name)} TO {$id($app['username'])}");
        }

        $this->info('Bootstrap complete. Next: php artisan arkon:migrate');

        return self::SUCCESS;
    }
}

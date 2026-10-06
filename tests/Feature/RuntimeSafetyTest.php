<?php

namespace Tests\Feature;

use App\Arkon\Database\ConfigurationException;
use App\Arkon\Database\MigrationConfig;
use App\Arkon\Database\RuntimeSafety;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\DatabaseTestCase;

/** Port of packages/core/test/config.test.ts. */
class RuntimeSafetyTest extends DatabaseTestCase
{
    private const KEY = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';

    public function test_accepts_a_minimal_runtime_environment(): void
    {
        RuntimeSafety::validateEnvironment([], self::KEY);
        $this->addToAssertionCount(1);
    }

    public function test_refuses_to_run_with_privileged_credentials_in_the_environment(): void
    {
        foreach (RuntimeSafety::PRIVILEGED_ENV_VARS as $name) {
            $this->assertThrows(fn () => RuntimeSafety::validateEnvironment([$name => 'secret'], self::KEY), ConfigurationException::class, $name);
        }
        $this->assertThrows(fn () => RuntimeSafety::validateEnvironment([], ''), ConfigurationException::class, 'APP_KEY');
    }

    public function test_migration_config_requires_distinct_roles_and_never_the_superuser(): void
    {
        MigrationConfig::validate('arkonlaravel_app', 'arkonlaravel_owner');
        $this->assertThrows(fn () => MigrationConfig::validate('same', 'same'), ConfigurationException::class, 'different roles');
        $this->assertThrows(fn () => MigrationConfig::validate('app', 'postgres'), ConfigurationException::class, 'superuser');
    }

    public function test_the_runtime_role_is_accepted_and_the_schema_owner_is_rejected(): void
    {
        RuntimeSafety::assertRestrictedRole(DB::connection());
        $owner = MigrationConfig::connect('test');
        try {
            $this->assertThrows(fn () => RuntimeSafety::assertRestrictedRole(DB::connection($owner)), ConfigurationException::class, 'owns');
        } finally {
            MigrationConfig::disconnect();
        }
    }

    public function test_the_web_app_refuses_to_serve_with_privileged_credentials_in_its_environment(): void
    {
        putenv('MIGRATION_DB_PASSWORD=leaked');
        try {
            $this->get('/login')->assertStatus(503)->assertSee('unsafe server configuration');
        } finally {
            putenv('MIGRATION_DB_PASSWORD');
        }
        $this->get('/login')->assertOk();
    }

    public function test_the_runtime_role_cannot_create_tables_or_read_the_migration_history(): void
    {
        $this->assertSame('42501', $this->sqlState(fn () => DB::statement('CREATE TABLE intruder (id int)')));
        $this->assertSame('42501', $this->sqlState(fn () => DB::table('migrations')->count()));
    }

    public function test_plain_artisan_migrate_is_refused_in_favour_of_arkon_migrate(): void
    {
        // Through the real console as a normal environment (Laravel skips console events under APP_ENV=testing).
        $process = new Process([PHP_BINARY, 'artisan', 'migrate', '--force'], base_path(), ['APP_ENV' => 'local', 'DB_DATABASE' => config('database.connections.pgsql.database')]);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('arkon:migrate', $process->getOutput().$process->getErrorOutput());
        // And as the runtime role it could not have changed anything anyway.
        $this->assertSame('42501', $this->sqlState(fn () => $this->artisan('migrate', ['--force' => true])->run()));
    }
}

<?php

namespace App\Console\Commands;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Database\MigrationConfig;
use App\Arkon\Database\SchemaMigrator;
use App\Arkon\Pages\PageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

final class RestoreBackupTest extends Command
{
    protected $signature = 'arkon:backup-restore-test {directory : Verified backup directory}';

    protected $description = 'Restore a backup into the isolated test database only, and verify publication reproduction';

    public function handle(): int
    {
        $pass = null;
        try {
            $dir = realpath((string) $this->argument('directory'));
            if (! $dir) {
                throw new \RuntimeException('Backup missing');
            }BackupWebsite::verify($dir);
            if (MigrationConfig::databaseFor('test') === MigrationConfig::databaseFor('dev')) {
                throw new \RuntimeException('Test database must differ');
            }
            $connection = MigrationConfig::connect('test');
            $cfg = config('database.connections.'.$connection);
            $private = storage_path('app/private/backups/drill-'.bin2hex(random_bytes(5)));
            File::makeDirectory($private, 0700, true);
            BackupWebsite::protect($private);
            $pass = $private.'/.pgpass';
            $escape = fn ($s) => str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $s);
            file_put_contents($pass, implode(':', array_map($escape, [$cfg['host'], $cfg['port'], $cfg['database'], $cfg['username'], $cfg['password']]))."\n");
            chmod($pass, 0600);
            // Remove only the isolated test schema; a backup may predate tables in today's test database.
            DB::connection($connection)->statement('DROP SCHEMA public CASCADE');
            DB::connection($connection)->statement('CREATE SCHEMA public');
            $restore = new Process([BackupWebsite::binary('pg_restore'), '--clean', '--if-exists', '--exit-on-error', '--no-owner', '--no-acl', '--host='.$cfg['host'], '--port='.$cfg['port'], '--username='.$cfg['username'], '--dbname='.$cfg['database'], $dir.'/database.dump'], null, ['PGPASSFILE' => $pass, 'PGPASSWORD' => false, 'PGSERVICE' => false]);
            $restore->setTimeout(600);
            $restore->mustRun();
            app(SchemaMigrator::class)->grant($connection, (string) config('database.connections.pgsql.username'));
            unlink($pass);
            $pass = null;
            MigrationConfig::disconnect();
            DB::purge('pgsql');
            config(['database.connections.pgsql.database' => MigrationConfig::databaseFor('test'), 'arkon.theme_store' => $dir.'/theme-components', 'arkon.media_root' => $dir.'/media']);
            app()->forgetInstance(ComponentRegistry::class);
            app()->forgetInstance(PageService::class);
            $failed = 0;
            $count = 0;
            foreach (DB::table('publications')->get(['id', 'site_id']) as $p) {
                $r = app(PageService::class)->reproducePublication($p->site_id, $p->id);
                $count++;
                if ($r['matches'] !== true && $r['status'] !== 'legacy') {
                    $failed++;
                }
            }
            $this->info("Isolated restore completed: {$count} publications checked, {$failed} reproduction failures. Development database and files were untouched.");

            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Restore drill failed. Development was not targeted. Check the backup and test database configuration.');

            return self::FAILURE;
        } finally {
            if ($pass && is_file($pass)) {
                unlink($pass);
            }MigrationConfig::disconnect();
        }
    }
}

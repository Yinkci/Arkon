<?php

namespace App\Console\Commands;

use App\Arkon\Database\MigrationConfig;
use App\Arkon\Media\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** Local disaster-recovery bundle; never reachable through a web route or AI tool. */
final class BackupWebsite extends Command
{
    protected $signature = 'arkon:backup {--target=dev : dev, test or e2e} {--verify= : Verify an existing backup directory instead}';

    protected $description = 'Back up PostgreSQL, uploaded media and immutable theme snapshots, with SHA-256 verification';

    public function handle(): int
    {
        $pass = null;
        $db = null;
        try {
            if ($path = $this->option('verify')) {
                self::verify((string) $path);
                $this->info('Backup checksums and PostgreSQL archive verified.');

                return self::SUCCESS;
            }
            $target = (string) $this->option('target');
            $connection = MigrationConfig::connect($target);
            $cfg = config('database.connections.'.$connection);
            $dir = storage_path('app/private/backups/'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(5)));
            File::makeDirectory($dir, 0700, true);
            self::protect($dir);
            $pass = $dir.'/.pgpass';
            $escape = fn ($s) => str_replace(['\\', ':'], ['\\\\', '\\:'], (string) $s);
            file_put_contents($pass, implode(':', array_map($escape, [$cfg['host'], $cfg['port'], $cfg['database'], $cfg['username'], $cfg['password']]))."\n");
            chmod($pass, 0600);
            $db = DB::connection($connection);
            $db->beginTransaction();
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $snapshot = $db->selectOne('SELECT pg_export_snapshot() AS id')->id;
            try {
                $dump = new Process([self::binary('pg_dump'), '--format=custom', '--no-owner', '--no-acl', '--host='.$cfg['host'], '--port='.$cfg['port'], '--username='.$cfg['username'], '--dbname='.$cfg['database'], '--snapshot='.$snapshot, '--file='.$dir.'/database.dump'], null, ['PGPASSFILE' => $pass, 'PGPASSWORD' => false, 'PGSERVICE' => false]);
                $dump->setTimeout(600);
                $dump->mustRun();
                self::copy((string) MediaStorage::fromConfig()->root, $dir.'/media');
                self::copy((string) config('arkon.theme_store'), $dir.'/theme-components');
            } finally {
                $db->rollBack();
                if (is_file($pass)) {
                    unlink($pass);
                }MigrationConfig::disconnect();
            }
            $hashes = [];
            foreach (File::allFiles($dir) as $f) {
                $hashes[str_replace('\\', '/', $f->getRelativePathname())] = hash_file('sha256', $f->getPathname());
            }ksort($hashes);
            file_put_contents($dir.'/manifest.json', json_encode(['format' => 1, 'createdAt' => gmdate('c'), 'database' => $cfg['database'], 'files' => $hashes, 'encryptionKeyIncluded' => false, 'note' => 'Keep the original APP_KEY separately in a password manager. It is required to decrypt stored enquiries. Environment files and helper credentials are excluded.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            self::verify($dir);
            $this->info('Backup created and verified: '.$dir);
            $this->line('Keep a protected copy on a separate disk and keep APP_KEY separately. Run a restore drill before launch.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Backup failed. Check the PostgreSQL tools, storage permissions and migration configuration. No credentials were printed.');

            return self::FAILURE;
        } finally {
            if ($db && $db->transactionLevel() > 0) {
                $db->rollBack();
            }
            if ($pass && is_file($pass)) {
                unlink($pass);
            }
            MigrationConfig::disconnect();
        }
    }

    public static function verify(string $dir): array
    {
        $root = realpath($dir);
        if (! $root || ! is_file($root.'/manifest.json')) {
            throw new \RuntimeException('Backup manifest missing');
        }$m = json_decode(file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($m['format'] ?? null) !== 1 || empty($m['files']['database.dump'])) {
            throw new \RuntimeException('Unsupported backup');
        }
        foreach ($m['files'] as $p => $hash) {
            if (! is_string($p) || str_contains($p, '..') || str_starts_with($p, '/') || str_contains($p, ':') || str_contains($p, '\\')) {
                throw new \RuntimeException('Invalid backup path');
            }$file = realpath($root.'/'.$p);
            if (! $file || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || is_link($root.'/'.$p) || ! hash_equals($hash, hash_file('sha256', $file))) {
                throw new \RuntimeException('Backup file damaged');
            }
        }
        $list = new Process([self::binary('pg_restore'), '--list', $root.'/database.dump']);
        $list->setTimeout(60);
        $list->mustRun();

        return $m;
    }

    public static function binary(string $name): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (array_reverse(glob('C:/Program Files/PostgreSQL/*/bin/'.$name.'.exe') ?: []) as $file) {
                return $file;
            }
        }

        return $name;
    }

    private static function copy(string $from, string $to): void
    {
        File::makeDirectory($to, 0700, true, true);
        if (! is_dir($from)) {
            return;
        }
        foreach (File::allFiles($from) as $f) {
            if ($f->isLink()) {
                throw new \RuntimeException('Refusing symlink in backup');
            }$target = $to.'/'.$f->getRelativePathname();
            File::ensureDirectoryExists(dirname($target), 0700);
            if (! copy($f->getPathname(), $target)) {
                throw new \RuntimeException('File copy failed');
            }
        }
    }

    public static function protect(string $dir): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($dir, 0700);

            return;
        }
        $quoted = "'".str_replace("'", "''", $dir)."'";
        $script = '& {param($target) $sid=[System.Security.Principal.WindowsIdentity]::GetCurrent().User; $acl=New-Object System.Security.AccessControl.DirectorySecurity; $acl.SetOwner($sid); $acl.SetAccessRuleProtection($true,$false); $rule=New-Object System.Security.AccessControl.FileSystemAccessRule($sid,"FullControl","ContainerInherit,ObjectInherit","None","Allow"); $acl.AddAccessRule($rule); Set-Acl -LiteralPath $target -AclObject $acl -ErrorAction Stop} '.$quoted;
        (new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script]))->mustRun();
    }
}

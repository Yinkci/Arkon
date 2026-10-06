<?php

namespace App\Console\Commands;

use App\Arkon\Database\MigrationConfig;
use App\Arkon\Database\SchemaMigrator;
use Illuminate\Console\Command;

class Migrate extends Command
{
    protected $signature = 'arkon:migrate {--target=dev : dev, test or e2e}';

    protected $description = 'Apply migrations and runtime grants as the schema owner, then pending data upgrades';

    public function handle(SchemaMigrator $migrator): int
    {
        $target = (string) $this->option('target');
        if (! in_array($target, MigrationConfig::TARGETS, true)) {
            $this->error("Unknown target {$target}");

            return self::FAILURE;
        }
        $migrator->migrate($target, log: fn (string $message) => $this->line($message));
        $this->info('Migrations, grants and data upgrades applied ('.MigrationConfig::databaseFor($target).')');

        return self::SUCCESS;
    }
}

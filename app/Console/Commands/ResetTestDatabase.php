<?php

namespace App\Console\Commands;

use App\Arkon\Database\TestDatabase;
use Illuminate\Console\Command;

class ResetTestDatabase extends Command
{
    protected $signature = 'arkon:reset-test-database {--target=e2e : test or e2e (never dev)}';

    protected $description = 'Migrate and empty a test database (used by the Playwright setup)';

    public function handle(): int
    {
        $target = (string) $this->option('target');
        if (! in_array($target, ['test', 'e2e'], true)) {
            $this->error('Only the test and e2e databases can be reset.');

            return self::FAILURE;
        }
        TestDatabase::migrate($target);
        TestDatabase::truncate($target);
        $this->info("Database for {$target} migrated and emptied.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Arkon\Database\ConfigurationException;
use App\Arkon\Database\RuntimeSafety;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckRuntime extends Command
{
    protected $signature = 'arkon:check-runtime';

    protected $description = 'Verify the web app would run with least privilege (environment and database role)';

    public function handle(): int
    {
        try {
            RuntimeSafety::validateEnvironment(RuntimeSafety::processEnvironment(), config('app.key'));
            RuntimeSafety::assertRestrictedRole(DB::connection());
        } catch (ConfigurationException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Runtime configuration is restricted: no privileged credentials, role '.DB::connection()->getConfig('username').' cannot change the schema.');

        return self::SUCCESS;
    }
}

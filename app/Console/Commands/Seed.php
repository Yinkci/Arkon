<?php

namespace App\Console\Commands;

use App\Arkon\Setup\SetupService;
use Illuminate\Console\Command;

class Seed extends Command
{
    protected $signature = 'arkon:seed {--host=* : Hostnames served by the demo site (default: ARKON_SEED_HOSTS)}';

    protected $description = 'Create the demo site with an unpublished home page (idempotent)';

    public function handle(SetupService $setup): int
    {
        $hosts = $this->option('host') ?: explode(',', (string) config('arkon.seed_hosts'));
        $result = $setup->seedDemoSite($hosts);
        $this->info($result['created']
            ? "Created demo site {$result['siteId']} for ".implode(', ', $hosts)
            : "Demo site already exists ({$result['siteId']})");
        $this->line('Next: php artisan arkon:owner-create --email=you@example.com --name="Your Name"');

        return self::SUCCESS;
    }
}

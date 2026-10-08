<?php

namespace App\Console\Commands;

use App\Arkon\Themes\ThemeStore;
use Illuminate\Console\Command;
use Throwable;

final class ThemeCommand extends Command
{
    protected $signature = 'arkon:theme {action : validate or install} {directory : Absolute theme folder}';

    protected $description = 'Validate a developer theme, or install immutable component snapshots (does not publish pages)';

    public function handle(): int
    {
        try {
            $packages = match ($this->argument('action')) {
                'validate' => ThemeStore::validate($this->argument('directory')),
                'install' => ThemeStore::install($this->argument('directory')),
                default => throw new \RuntimeException('Use validate or install.'),
            };
            foreach ($packages as $p) {
                $this->info($p['manifest']['type'].' v'.$p['manifest']['version'].' '.$p['hash']);
            }
            $this->info('Validated'.($this->argument('action') === 'install' ? ' and installed. Reload the builder to discover these components. Existing publications were not changed.' : '.'));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}

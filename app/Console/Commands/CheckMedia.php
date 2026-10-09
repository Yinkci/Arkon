<?php

namespace App\Console\Commands;

use App\Arkon\Media\MediaVariants;
use App\Arkon\Media\UploadPolicy;
use Illuminate\Console\Command;

class CheckMedia extends Command
{
    protected $signature = 'arkon:check-media';

    protected $description = 'Check this PHP process supports the configured image upload limit';

    public function handle(): int
    {
        $policy = UploadPolicy::forRuntime();
        $this->info('Application limit: '.UploadPolicy::formatBytes($policy['maxImageUploadBytes']).' ('.$policy['maxImageUploadBytes'].' bytes)');
        $this->info('PHP upload_max_filesize: '.ini_get('upload_max_filesize').'; post_max_size: '.ini_get('post_max_size'));
        $this->info('Effective file limit with request headroom: '.UploadPolicy::formatBytes($policy['effectiveMaxBytes']));
        $this->info('WebP encoder: '.(MediaVariants::supported() ? 'available' : 'unavailable; originals remain usable'));
        $this->comment('Verify the serving PHP process and proxy separately. This command only checks its own PHP runtime.');
        if ($policy['runtimeLimited']) {
            $this->error('PHP limits are below the application policy. Configure upload_max_filesize=8M, post_max_size=10M and restart the serving PHP process.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

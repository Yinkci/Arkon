<?php

namespace App\Console\Commands;

use App\Arkon\Media\MediaVariants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class MediaVariantsCommand extends Command
{
    protected $signature = 'arkon:media-variants';

    protected $description = 'Create the responsive WebP sizes for images uploaded before they existed (originals are kept unchanged)';

    public function handle(MediaVariants $variants): int
    {
        if (! MediaVariants::supported()) {
            $this->error('This PHP has no GD WebP support.');

            return self::FAILURE;
        }
        $created = 0;
        foreach (DB::table('media_assets')->orderBy('created_at')->cursor() as $asset) {
            try {
                $created += $variants->generate($asset);
            } catch (Throwable $error) {
                $this->warn("{$asset->id}: {$error->getMessage()}");
            }
        }
        $this->info("Created {$created} variants.");

        return self::SUCCESS;
    }
}

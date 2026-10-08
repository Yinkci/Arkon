<?php

namespace App\Console\Commands;

use App\Arkon\Pages\PageService;
use Illuminate\Console\Command;

class RecordRenderCompat extends Command
{
    protected $signature = 'arkon:record-render-compat {publication : Publication id} {build : Renderer build, e.g. arkon-php-2-pre-basis} {--reason= : Why (kept with the record)}';

    protected $description = 'Record which development renderer build produced a publication (only if that build reproduces it byte for byte)';

    public function handle(PageService $pages): int
    {
        $reason = trim((string) ($this->option('reason') ?: 'Published while this renderer version was still in development'));
        $result = $pages->recordRenderCompat((string) $this->argument('publication'), (string) $this->argument('build'), mb_substr($reason, 0, 500));
        $result['recorded'] ? $this->info($result['reason']) : $this->error('Not recorded: '.$result['reason']);

        return $result['recorded'] ? self::SUCCESS : self::FAILURE;
    }
}

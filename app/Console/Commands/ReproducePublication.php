<?php

namespace App\Console\Commands;

use App\Arkon\Pages\PageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReproducePublication extends Command
{
    protected $signature = 'arkon:reproduce-publication {publication : Publication id}';

    protected $description = 'Render a publication again from its revision and recorded inputs, and compare with the stored HTML';

    public function handle(PageService $pages): int
    {
        $id = (string) $this->argument('publication');
        $siteId = DB::table('publications')->where('id', $id)->value('site_id');
        if ($siteId === null) {
            $this->error('Publication not found.');

            return self::FAILURE;
        }
        $result = $pages->reproducePublication($siteId, $id);
        match ($result['status']) {
            'reproduced' => $result['matches']
                ? $this->info('Reproduced byte for byte.')
                : $this->error('Reproduced, but the output differs from the stored HTML.'),
            'legacy' => $this->warn('Legacy publication: '.$result['reason'].'. Its stored HTML is authoritative.'),
            default => $this->error('Cannot reproduce: '.$result['reason']),
        };

        return $result['status'] === 'reproduced' && $result['matches'] ? self::SUCCESS : self::FAILURE;
    }
}

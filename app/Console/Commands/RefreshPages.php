<?php

namespace App\Console\Commands;

use App\Arkon\Design\PageRefreshes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshPages extends Command
{
    protected $signature = 'arkon:refresh-pages {--retry-failed : Put failed refreshes back in the queue first}';

    protected $description = 'Re-render live pages whose design tokens or reusable components were published (pending refreshes)';

    public function handle(PageRefreshes $refreshes): int
    {
        if ($this->option('retry-failed')) {
            DB::table('page_refreshes')->where('status', 'failed')->update(['status' => 'pending', 'attempts' => 0, 'updated_at' => DB::raw('now()')]);
        }
        $failed = 0;
        foreach (DB::table('page_refreshes')->where('status', 'pending')->distinct()->pluck('site_id') as $siteId) {
            $counts = $refreshes->run((string) $siteId, limit: 1000, seconds: 600);
            $this->line("Site {$siteId}: {$counts['done']} refreshed, {$counts['skipped']} skipped, {$counts['failed']} failed");
            $failed += $counts['failed'];
        }
        $left = DB::table('page_refreshes')->whereIn('status', ['pending', 'failed'])->count();
        $this->info($left === 0 ? 'Every live page is up to date.' : "{$left} refreshes still open (see the Design page).");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}

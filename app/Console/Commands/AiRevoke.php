<?php

namespace App\Console\Commands;

use App\Arkon\Ai\AiConnections;
use Illuminate\Console\Command;

class AiRevoke extends Command
{
    protected $signature = 'arkon:ai-revoke {id : Connection id (see arkon:ai-connections)}';

    protected $description = 'Revoke a paired AI connection; its token stops working immediately';

    public function handle(AiConnections $connections): int
    {
        if (! $connections->revoke((string) $this->argument('id'))) {
            $this->error('No active connection with that id.');

            return self::FAILURE;
        }
        $this->info('Revoked.');

        return self::SUCCESS;
    }
}

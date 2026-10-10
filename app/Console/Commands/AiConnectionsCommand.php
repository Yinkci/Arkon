<?php

namespace App\Console\Commands;

use App\Arkon\Ai\AiConnections;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiConnectionsCommand extends Command
{
    protected $signature = 'arkon:ai-connections';

    protected $description = 'List paired AI connections (helper and MCP)';

    public function handle(AiConnections $connections): int
    {
        $emails = DB::table('users')->pluck('email', 'id');
        $rows = array_map(fn ($c) => [
            $c->id, $c->kind, $c->name, $emails[$c->user_id] ?? '?', $c->last_seen_at ?? 'never', $c->revoked_at ? "revoked {$c->revoked_at}" : 'active',
        ], $connections->list());
        $this->table(['id', 'kind', 'name', 'user', 'last seen', 'state'], $rows);

        return self::SUCCESS;
    }
}

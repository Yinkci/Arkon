<?php

namespace App\Console\Commands;

use App\Arkon\Ai\Mcp\McpServer;
use Illuminate\Console\Command;

/**
 * Arkon's MCP server over stdio, started by Claude Code (see `php artisan arkon:ai-pair --mcp`
 * for the registration command). stdout carries only protocol messages; nothing else is printed.
 */
class McpCommand extends Command
{
    protected $signature = 'arkon:mcp';

    protected $description = 'Arkon MCP server for Claude Code (stdio); authenticated by ARKON_MCP_TOKEN';

    public function handle(): int
    {
        $token = getenv('ARKON_MCP_TOKEN');
        $server = app()->make(McpServer::class, ['token' => is_string($token) && $token !== '' ? $token : null]);
        $in = fopen('php://stdin', 'r');
        $out = fopen('php://stdout', 'w');
        while (($line = fgets($in)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $response = $server->handleLine(trim($line));
            if ($response !== null) {
                fwrite($out, $response."\n");
                fflush($out);
            }
        }

        return self::SUCCESS;
    }
}

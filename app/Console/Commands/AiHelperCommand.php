<?php

namespace App\Console\Commands;

use App\Arkon\Ai\AiConnections;
use App\Arkon\Ai\AiHelper;
use App\Arkon\Ai\ClaudeRunner;
use App\Arkon\Ai\ProposalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The local helper for the editor's AI panel. Run it in a terminal under your own Windows
 * account; it runs the Claude Code CLI with your normal subscription login for requests
 * queued in the panel, and reports its readiness so the panel can show it.
 *
 *   php artisan arkon:ai-helper
 *
 * Its Arkon token comes from ARKON_HELPER_TOKEN or the file written by
 * `php artisan arkon:ai-pair you@example.com --helper`. Stop it with Ctrl+C; a request it was
 * working on is picked up again (once) by the next helper after its lease expires.
 */
class AiHelperCommand extends Command
{
    protected $signature = 'arkon:ai-helper {--once : Handle at most one request, then exit (for checks)}';

    protected $description = 'Run the local Claude Code helper for the editor\'s AI panel';

    public function handle(AiConnections $connections, ProposalService $proposals, ClaudeRunner $runner): int
    {
        $helper = new AiHelper($proposals, $connections, $runner);
        $say = fn (string $line) => $this->line('['.date('H:i:s')."] {$line}");
        $say('Arkon AI helper starting. Leave this window open while you use the AI panel; Ctrl+C stops it.');
        $status = $helper->status();
        $say($status->message);

        $connection = null;
        $waiting = null;
        $lastStatus = $status->message;
        while (true) {
            try {
                $connection = $connections->resolve($this->token(), 'helper');
                if ($connection === null) {
                    $this->wait($say, $waiting, 'Not paired yet (or the token was revoked). Pair it in another terminal: php artisan arkon:ai-pair you@example.com --helper');
                    if ($this->option('once')) {
                        return self::FAILURE;
                    }
                    sleep(3);

                    continue;
                }
                $waiting = null;
                $outcome = $helper->tick($connection, $say);
                $current = $helper->status()->message;
                if ($current !== $lastStatus) {
                    $say($current);
                    $lastStatus = $current;
                }
                if ($this->option('once')) {
                    return self::SUCCESS;
                }
                if ($outcome === null) {
                    sleep(1);
                }
            } catch (Throwable $error) {
                // Database not reachable yet, restarted, … — keep trying; nothing is lost (requests are durable).
                $this->wait($say, $waiting, 'Waiting for the database: '.mb_substr($error->getMessage(), 0, 160));
                DB::disconnect();
                if ($this->option('once')) {
                    return self::FAILURE;
                }
                sleep(3);
            }
        }
    }

    private function token(): ?string
    {
        $env = getenv('ARKON_HELPER_TOKEN');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        $file = (string) config('arkon.ai.helper_token_file');

        return is_file($file) ? trim((string) file_get_contents($file)) : null;
    }

    private function wait(callable $say, ?string &$waiting, string $message): void
    {
        if ($waiting !== $message) {
            $say($message);
            $waiting = $message;
        }
    }
}

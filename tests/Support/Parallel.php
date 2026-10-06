<?php

namespace Tests\Support;

use App\Arkon\Sites\SiteContext;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Starts service calls in separate PHP processes that all begin at the same
 * instant. Workers get only the runtime database role's settings (the test
 * database); the schema-owner credentials never reach them.
 */
final class Parallel
{
    /** @var list<Process> */
    private array $processes = [];

    public readonly float $startAt;

    public function __construct(float $leadSeconds = 2.5)
    {
        $this->startAt = microtime(true) + $leadSeconds;
    }

    public function add(string $call, SiteContext $ctx, array $input, int $delayMs = 0): self
    {
        $job = base64_encode(json_encode([
            'call' => $call,
            'ctx' => ['siteId' => $ctx->siteId, 'userId' => $ctx->userId],
            'input' => $input,
            'startAt' => $this->startAt,
            'delayMs' => $delayMs,
        ], JSON_THROW_ON_ERROR));
        $process = new Process([PHP_BINARY, base_path('tests/Support/worker.php'), $job], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'ARKON_MEDIA_ROOT' => config('arkon.media_root'),
        ]);
        $process->setTimeout(60);
        $process->start();
        $this->processes[] = $process;

        return $this;
    }

    /** @return list<array{ok: bool, late: bool, result?: array, code?: string, class?: string, message?: string}> */
    public function wait(): array
    {
        $results = [];
        foreach ($this->processes as $process) {
            $process->wait();
            $decoded = json_decode(trim($process->getOutput()), true);
            if (! is_array($decoded)) {
                throw new RuntimeException('Worker failed: '.$process->getOutput().$process->getErrorOutput());
            }
            if ($decoded['late']) {
                throw new RuntimeException('A worker booted after the start time; increase the lead time');
            }
            $results[] = $decoded;
        }

        return $results;
    }

    /** Sleeps until `$offsetMs` after the common start time. */
    public function sleepUntil(int $offsetMs): void
    {
        $target = $this->startAt + $offsetMs / 1000;
        while (microtime(true) < $target) {
            usleep(500);
        }
    }
}

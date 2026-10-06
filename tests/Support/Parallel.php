<?php

namespace Tests\Support;

use App\Arkon\Sites\SiteContext;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs service calls in separate PHP processes (separate connections, real lock
 * waits). Uses a ready barrier, not a startup timer: every worker boots and
 * connects, reports READY, and then waits for a go-file that `release()` writes
 * with one common start instant (a file, because stdin is only flushed while the
 * parent pumps the process, and the parent may be busy holding locks). However slow the machine, no call starts before
 * every worker is ready, and none is ever "late".
 *
 * Workers get only the runtime database role's settings (the test database);
 * the schema-owner credentials never reach them.
 */
final class Parallel
{
    private const READY_TIMEOUT = 120.0;

    /** @var list<array{process: Process, output: string, ready: bool}> */
    private array $workers = [];

    private ?float $startAt = null;

    private readonly string $goFile;

    public function __construct()
    {
        $dir = storage_path('testing');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->goFile = $dir.DIRECTORY_SEPARATOR.'parallel-'.bin2hex(random_bytes(8)).'.go';
    }

    /** Runs one call in a worker and returns its result (no barrier needed). */
    public static function run(string $call, SiteContext $ctx, array $input): array
    {
        return (new self)->add($call, $ctx, $input)->wait()[0];
    }

    public function add(string $call, SiteContext $ctx, array $input, int $delayMs = 0): self
    {
        $job = base64_encode(json_encode([
            'call' => $call,
            'ctx' => ['siteId' => $ctx->siteId, 'userId' => $ctx->userId],
            'input' => $input,
            'delayMs' => $delayMs,
            'goFile' => $this->goFile,
        ], JSON_THROW_ON_ERROR));
        $process = new Process([PHP_BINARY, base_path('tests/Support/worker.php'), $job], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'ARKON_MEDIA_ROOT' => config('arkon.media_root'),
        ], null, null);
        $process->start();
        $this->workers[] = ['process' => $process, 'output' => '', 'ready' => false];

        return $this;
    }

    /** Blocks until every worker has booted and connected to the database. */
    public function ready(): self
    {
        $deadline = microtime(true) + self::READY_TIMEOUT;
        foreach ($this->workers as $i => $worker) {
            while (! $worker['ready']) {
                $worker['output'] .= $worker['process']->getIncrementalOutput();
                $worker['ready'] = str_contains($worker['output'], "READY\n");
                if (! $worker['ready'] && ! $worker['process']->isRunning()) {
                    throw new RuntimeException('Worker exited before it was ready: '.$worker['output'].$worker['process']->getErrorOutput());
                }
                if (! $worker['ready'] && microtime(true) > $deadline) {
                    throw new RuntimeException('Worker not ready after '.self::READY_TIMEOUT.' seconds');
                }
                if (! $worker['ready']) {
                    usleep(10_000);
                }
            }
            $this->workers[$i] = $worker;
        }

        return $this;
    }

    /** Lets every (ready) worker start its call at one common instant, shortly from now. */
    public function release(): self
    {
        if ($this->startAt !== null) {
            return $this;
        }
        $this->ready();
        $this->startAt = microtime(true) + 0.05;
        // Written under a temporary name first, so a worker never reads a half-written file.
        file_put_contents($this->goFile.'.tmp', json_encode(['startAt' => $this->startAt]));
        rename($this->goFile.'.tmp', $this->goFile);

        return $this;
    }

    /** @return list<array{ok: bool, result?: array, code?: string, class?: string, message?: string}> */
    public function wait(): array
    {
        $this->release();
        $results = [];
        foreach ($this->workers as $worker) {
            $worker['process']->wait();
            $output = $worker['output'].$worker['process']->getIncrementalOutput();
            $lines = array_values(array_filter(explode("\n", trim($output)), fn ($l) => $l !== 'READY'));
            $decoded = json_decode((string) end($lines), true);
            if (! is_array($decoded)) {
                throw new RuntimeException('Worker failed: '.$output.$worker['process']->getErrorOutput());
            }
            $results[] = $decoded;
        }
        @unlink($this->goFile);

        return $results;
    }

    /** Sleeps until `$offsetMs` after the common start instant (releasing the workers if needed). */
    public function sleepUntil(int $offsetMs): void
    {
        $this->release();
        $target = $this->startAt + $offsetMs / 1000;
        while (microtime(true) < $target) {
            usleep(500);
        }
    }
}

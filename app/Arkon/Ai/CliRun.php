<?php

namespace App\Arkon\Ai;

/**
 * One running provider process (see CliProcess). poll() returns null while it runs and its result when
 * it ends; it throws (after stopping the whole process tree) on the run's time limit, a watchdog verdict,
 * cancellation or oversized output. close() always stops and cleans up.
 */
final class CliRun
{
    private const MAX_OUTPUT = 8 * 1024 * 1024;

    /** Stderr may carry a provider's stream trace (one line per received model frame), so it gets more room. */
    private const MAX_STDERR = 64 * 1024 * 1024;

    /** @var resource|null */
    private $process;

    private string $io;

    private string $out;

    private string $err;

    private float $started;

    private float $lastCheck;

    private int $offset = 0;

    private int $errOffset = 0;

    private ?int $pid = null;

    private string $stderr = '';

    /** @param list<string> $argv */
    public function __construct(array $argv, ?string $cwd, string $input, private readonly int $timeout, private readonly ?\Closure $keepGoing, private readonly ?\Closure $onOutput, private readonly ?\Closure $watchdog, string $missingCode, private readonly string $timeoutCode, private readonly ?\Closure $onError = null, array $env = [])
    {
        $this->io = CliProcess::tempDir();
        file_put_contents($in = $this->io.DIRECTORY_SEPARATOR.'stdin', $input);
        $descriptors = [0 => ['file', $in, 'r'], 1 => ['file', $this->out = $this->io.DIRECTORY_SEPARATOR.'stdout', 'w'], 2 => ['file', $this->err = $this->io.DIRECTORY_SEPARATOR.'stderr', 'w']];
        $process = @proc_open($argv, $descriptors, $pipes, $cwd ?? $this->io, [...CliProcess::environment(), ...$env], ['bypass_shell' => true, 'suppress_errors' => true]);
        if (! is_resource($process)) {
            CliProcess::removeDir($this->io);
            throw new AiException($missingCode, 'The AI executable could not be started.');
        }
        $this->process = $process;
        $this->pid = proc_get_status($process)['pid'] ?? null;
        $this->started = $this->lastCheck = microtime(true);
    }

    public function pid(): ?int
    {
        return $this->pid;
    }

    public function elapsed(): float
    {
        return microtime(true) - $this->started;
    }

    /** @return array{code: int, out: string, err: string}|null */
    public function poll(): ?array
    {
        if ($this->process === null) {
            throw new \LogicException('This run is closed.');
        }
        clearstatcache(true, $this->out);
        clearstatcache(true, $this->err);
        $size = (int) @filesize($this->out);
        $errSize = (int) @filesize($this->err);
        if ($size > self::MAX_OUTPUT || $errSize > self::MAX_STDERR) {
            $this->fail(new AiException(AiException::INVALID_OUTPUT, 'The provider output exceeded the safe transport limit.'));
        }
        $this->drain($size);
        $this->drainErr($errSize);
        $status = proc_get_status($this->process);
        if (! $status['running']) {
            $code = (int) $status['exitcode'];
            proc_close($this->process);
            $this->process = null;
            clearstatcache(true, $this->out);
            clearstatcache(true, $this->err);
            $this->drain((int) @filesize($this->out));
            $this->drainErr((int) @filesize($this->err));
            $result = ['code' => $code, 'out' => (string) @file_get_contents($this->out), 'err' => $this->stderr = (string) @file_get_contents($this->err)];
            CliProcess::removeDir($this->io);

            return $result;
        }
        $elapsed = $this->elapsed();
        if ($elapsed > $this->timeout) {
            $this->fail(new AiException($this->timeoutCode, "The AI executable did not finish within {$this->timeout} seconds."));
        }
        if ($this->watchdog !== null && ($verdict = ($this->watchdog)($elapsed)) instanceof AiException) {
            $this->fail($verdict);
        }
        if ($this->keepGoing !== null && microtime(true) - $this->lastCheck >= 2) {
            $this->lastCheck = microtime(true);
            if (! ($this->keepGoing)()) {
                $this->fail(new AiException(AiException::CANCELLED, 'The request was cancelled.'));
            }
        }

        return null;
    }

    /** Stops the process tree if it still runs and removes its files. Safe to call more than once. */
    public function close(): void
    {
        if ($this->process !== null) {
            $status = proc_get_status($this->process);
            $status['running'] ? CliProcess::stop($this->process, $status['pid']) : proc_close($this->process);
            $this->process = null;
        }
        CliProcess::removeDir($this->io);
    }

    /** What the provider wrote to stderr (kept after the run is stopped, for diagnostics). */
    public function stderr(): string
    {
        return $this->process !== null ? (string) @file_get_contents($this->err) : $this->stderr;
    }

    private function drain(int $size): void
    {
        if ($this->onOutput !== null && $size > $this->offset) {
            $chunk = (string) file_get_contents($this->out, false, null, $this->offset, $size - $this->offset);
            $this->offset = $size;
            ($this->onOutput)($chunk);
        }
    }

    /** Stderr read while the provider runs (only when someone listens), in whole lines. */
    private function drainErr(int $size): void
    {
        if ($this->onError === null || $size <= $this->errOffset) {
            return;
        }
        $chunk = (string) file_get_contents($this->err, false, null, $this->errOffset, $size - $this->errOffset);
        $end = strrpos($chunk, "\n");
        if ($end === false) {
            return;
        }
        $this->errOffset += $end + 1;
        ($this->onError)(substr($chunk, 0, $end + 1));
    }

    private function fail(AiException $e): never
    {
        $this->stderr = (string) @file_get_contents($this->err);
        $this->close();
        throw $e;
    }
}

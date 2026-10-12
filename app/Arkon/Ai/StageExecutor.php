<?php

namespace App\Arkon\Ai;

/**
 * Runs independent generation stages, up to $concurrency at once when the provider can start runs
 * without waiting (StagedRunner), one after another otherwise. Progress is reported per stage as it
 * changes. A stage whose provider gave no reply (stalled stream, lost connection, never started) is a
 * transient failure and is started again up to $retries times, reported as 'retrying'; any other failure
 * is not retried. After a failure nothing new starts; stages already running finish (their results are
 * kept so a resumed request does not generate them again). Cancellation stops every running process tree.
 */
final class StageExecutor
{
    /** Failures where the provider produced no reply: worth one more attempt of that stage only. */
    public const TRANSIENT = ['EXECUTION_TIMEOUT', 'PROVIDER_CONNECTION_FAILED', 'PROVIDER_NOT_STARTED', 'PROVIDER_STREAM_STALLED', 'PROVIDER_RUNAWAY_OUTPUT'];

    /**
     * @param  array<string, \Closure(): AiRequest>  $stages  built lazily, in order
     * @param  callable(string, string, array): void  $progress  (stage key, running|retrying|done|failed, details)
     * @return array{done: array<string, AiCompletion>, failed: array<string, AiException>}
     */
    public static function run(AiRunner $runner, array $stages, callable $keepGoing, callable $progress, int $concurrency = 3, int $retries = 0): array
    {
        $attempts = [];
        $done = [];
        $failed = [];
        if (! $runner instanceof StagedRunner) {
            foreach ($stages as $key => $build) {
                if ($failed !== []) {
                    break;
                }
                for ($attempt = 0; ; $attempt++) {
                    $progress($key, 'running', []);
                    $started = microtime(true);
                    try {
                        $done[$key] = $runner->run($build(), $keepGoing);
                        $progress($key, 'done', ['seconds' => round(microtime(true) - $started, 1)]);
                        break;
                    } catch (AiException $e) {
                        if ($e->code() !== AiException::CANCELLED && in_array($e->code(), self::TRANSIENT, true) && $attempt < $retries) {
                            $progress($key, 'retrying', ['seconds' => round(microtime(true) - $started, 1), 'code' => $e->code(), 'message' => $e->getMessage()]);

                            continue;
                        }
                        $failed[$key] = $e;
                        $progress($key, 'failed', ['seconds' => round(microtime(true) - $started, 1), 'code' => $e->code(), 'message' => $e->getMessage()]);
                        if ($e->code() === AiException::CANCELLED) {
                            throw $e;
                        }
                        break;
                    }
                }
            }

            return ['done' => $done, 'failed' => $failed];
        }
        $waiting = $stages;
        /** @var array<string, array{run: PendingRun, started: float}> $running */
        $running = [];
        try {
            while ($waiting !== [] || $running !== []) {
                while ($failed === [] && $waiting !== [] && count($running) < $concurrency) {
                    $key = array_key_first($waiting);
                    $build = $waiting[$key];
                    unset($waiting[$key]);
                    try {
                        $running[$key] = ['run' => $runner->start($build(), $keepGoing), 'started' => microtime(true)];
                        $progress($key, 'running', []);
                    } catch (AiException $e) {
                        $failed[$key] = $e;
                        $progress($key, 'failed', ['seconds' => 0, 'code' => $e->code(), 'message' => $e->getMessage()]);
                    }
                }
                if ($failed !== []) {
                    $waiting = [];
                }
                foreach ($running as $key => $r) {
                    try {
                        $completion = $r['run']->poll();
                    } catch (AiException $e) {
                        unset($running[$key]);
                        if ($e->code() === AiException::CANCELLED) {
                            $progress($key, 'failed', ['seconds' => round(microtime(true) - $r['started'], 1), 'code' => $e->code(), 'message' => $e->getMessage()]);
                            throw $e;
                        }
                        $attempts[$key] = ($attempts[$key] ?? 0) + 1;
                        if (in_array($e->code(), self::TRANSIENT, true) && $attempts[$key] <= $retries && $failed === []) {
                            $progress($key, 'retrying', ['seconds' => round(microtime(true) - $r['started'], 1), 'code' => $e->code(), 'message' => $e->getMessage()]);
                            $waiting = [$key => $stages[$key], ...$waiting];

                            continue;
                        }
                        $failed[$key] = $e;
                        $progress($key, 'failed', ['seconds' => round(microtime(true) - $r['started'], 1), 'code' => $e->code(), 'message' => $e->getMessage(), ...$r['run']->diagnostics()]);

                        continue;
                    }
                    if ($completion !== null) {
                        unset($running[$key]);
                        $done[$key] = $completion;
                        $progress($key, 'done', ['seconds' => round(microtime(true) - $r['started'], 1), 'usage' => $completion->usage]);
                    }
                }
                if ($running !== []) {
                    usleep(100_000);
                }
            }
        } finally {
            foreach ($running as $r) {
                $r['run']->close();
            }
        }

        return ['done' => $done, 'failed' => $failed];
    }
}

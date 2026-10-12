<?php

namespace App\Arkon\Ai;

use Carbon\Carbon;

/**
 * The stages of a staged run as the review screen shows them: each stage's label, state
 * (waiting, running, done, cached, failed), duration, reconnect attempts, plus when the provider last
 * produced output. Reported on every state change and at most every two seconds for provider output.
 */
final class StageProgress
{
    /** @var array<string, array{key: string, label: string, status: string, seconds: ?float, startedAt: ?float, reconnects: int, error: ?string}> */
    private array $stages = [];

    private ?float $lastOutput = null;

    private float $lastReport = 0;

    private ?array $cache = null;

    /** @param callable(array, ?array): void $report (progress document, stage cache to persist or null) */
    public function __construct(private $report) {}

    public function add(string $key, string $label): void
    {
        $this->stages[$key] ??= ['key' => $key, 'label' => $label, 'status' => 'waiting', 'seconds' => null, 'startedAt' => null, 'reconnects' => 0, 'retries' => 0, 'error' => null];
        $this->flush(true);
    }

    public function set(string $key, string $status, array $info = []): void
    {
        if (! isset($this->stages[$key])) {
            return;
        }
        $s = &$this->stages[$key];
        $s['status'] = $status;
        if ($status === 'running') {
            $s['startedAt'] = microtime(true);
        }
        if (isset($info['seconds'])) {
            $s['seconds'] = $info['seconds'];
        }
        if ($status === 'failed') {
            $s['error'] = mb_substr((string) ($info['message'] ?? ''), 0, 300);
        }
        if ($status === 'retrying') {
            $s['retries'] = ($s['retries'] ?? 0) + 1;
        }
        unset($s);
        $this->flush(true);
    }

    public function event(string $key, array $event): void
    {
        $this->lastOutput = microtime(true);
        if (($event['type'] ?? '') === 'reconnecting' && isset($this->stages[$key])) {
            $this->stages[$key]['reconnects']++;
            $this->flush(true);

            return;
        }
        $this->flush(false);
    }

    public function cache(array $cache): void
    {
        $this->cache = $cache;
        $this->flush(true);
    }

    public function label(string $key): string
    {
        return $this->stages[$key]['label'] ?? $key;
    }

    /** @return list<array> */
    public function stages(): array
    {
        return array_values(array_map(fn ($s) => array_diff_key($s, ['startedAt' => true]), $this->stages));
    }

    public function document(): array
    {
        $iso = fn (?float $t) => $t === null ? null : Carbon::createFromTimestampMs((int) ($t * 1000))->toIso8601String();

        return [
            'stages' => array_values(array_map(fn ($s) => [...$s, 'startedAt' => $s['status'] === 'running' ? $iso($s['startedAt']) : null], $this->stages)),
            'lastOutputAt' => $iso($this->lastOutput),
        ];
    }

    private function flush(bool $force): void
    {
        if (! $force && microtime(true) - $this->lastReport < 2) {
            return;
        }
        $this->lastReport = microtime(true);
        ($this->report)($this->document(), $this->cache);
        $this->cache = null;
    }
}

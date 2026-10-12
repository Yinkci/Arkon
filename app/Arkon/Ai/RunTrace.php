<?php

namespace App\Arkon\Ai;

use Illuminate\Support\Facades\Log;

/**
 * A provider run's timeline for the server log: when it started, when the provider first produced
 * output, every event (type, item kind, short detail), the longest silence, token usage and the end
 * (completed, failed, timed out, cancelled). No prompt text or secrets; bounded in size.
 */
final class RunTrace
{
    private float $start;

    private ?float $first = null;

    private float $last;

    private float $longestGap = 0;

    /** @var list<array{t: float, event: string}> */
    private array $events = [];

    private int $count = 0;

    public function __construct(private readonly string $provider, private readonly array $sizes = [])
    {
        $this->start = $this->last = microtime(true);
    }

    public function event(array $event): void
    {
        $now = microtime(true);
        $this->first ??= $now - $this->start;
        $this->longestGap = max($this->longestGap, $now - $this->last);
        $this->last = $now;
        $this->count++;
        $item = is_array($event['item'] ?? null) ? $event['item'] : [];
        $detail = match ($item['type'] ?? null) {
            'command_execution' => mb_substr((string) ($item['command'] ?? ''), 0, 80),
            'mcp_tool_call' => ($item['server'] ?? '').'/'.($item['tool'] ?? ''),
            'web_search' => 'search',
            'agent_message', 'reasoning' => strlen((string) ($item['text'] ?? '')).' chars',
            default => '',
        };
        $label = trim(($event['type'] ?? '?').' '.($item['type'] ?? '').' '.$detail);
        if (count($this->events) < 120) {
            $this->events[] = ['t' => round($now - $this->start, 1), 'event' => $label];
        }
    }

    /** Seconds since the last provider output (or since the start when there was none). */
    public function silence(): float
    {
        return microtime(true) - $this->last;
    }

    public function firstOutput(): ?float
    {
        return $this->first;
    }

    public function elapsed(): float
    {
        return microtime(true) - $this->start;
    }

    /**
     * @param  array<string, mixed>  $stream  what the provider's model stream did (frames, gaps), when it reports it;
     *                                        `longestSilence` is the silence of the event output, which can be far longer
     */
    public function finish(string $outcome, ?int $exit, string $stderr = '', array $usage = [], array $stream = []): array
    {
        $now = microtime(true);
        $this->longestGap = max($this->longestGap, $now - $this->last);
        $context = ['provider' => $this->provider, 'outcome' => $outcome, 'exit' => $exit, 'seconds' => round($now - $this->start, 1),
            'firstOutput' => $this->first === null ? null : round($this->first, 1), 'longestSilence' => round($this->longestGap, 1), 'events' => $this->count,
            ...$this->sizes, 'usage' => $usage, 'stream' => $stream, 'timeline' => $this->events, 'stderrTail' => mb_substr(trim($stderr), -800)];
        $outcome === 'completed' ? Log::info('AI provider run', $context) : Log::warning('AI provider run', $context);

        return $context;
    }
}

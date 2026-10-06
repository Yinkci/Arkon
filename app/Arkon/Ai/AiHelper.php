<?php

namespace App\Arkon\Ai;

/**
 * The local helper's work, one step at a time (the artisan command loops over tick()):
 * report readiness, recover interrupted runs, lease the next queued request of the helper's
 * site, run Claude Code for it, and keep the connection alive while Claude works.
 */
final class AiHelper
{
    private ?RunnerStatus $status = null;

    private float $checkedAt = 0;

    public function __construct(
        private readonly ProposalService $proposals,
        private readonly AiConnections $connections,
        private readonly ClaudeRunner $runner,
        private readonly int $recheckSeconds = 60,
    ) {}

    /** Claude Code's readiness, checked at start and then every minute (or after a login/limit failure). */
    public function status(bool $force = false): RunnerStatus
    {
        if ($force || $this->status === null || microtime(true) - $this->checkedAt > $this->recheckSeconds) {
            $this->status = $this->runner->check();
            $this->checkedAt = microtime(true);
        }

        return $this->status;
    }

    /**
     * @param  callable(string): void  $say  progress lines for the terminal
     * @return string|null what happened (null when idle)
     */
    public function tick(object $connection, ?callable $say = null): ?string
    {
        $say ??= fn () => null;
        $status = $this->status();
        $this->connections->heartbeat($connection->id, $status);
        if (! $status->ready) {
            return null;
        }
        $claim = $this->proposals->claimNext($connection);
        if ($claim === null) {
            return null;
        }
        $say("Working on request {$claim->id} (attempt {$claim->attempts}): ".mb_substr(preg_replace('/\s+/', ' ', $claim->prompt), 0, 80));
        $outcome = $this->proposals->execute($claim, $this->runner, fn () => $this->connections->heartbeat($connection->id, $status));
        $say("Request {$claim->id}: {$outcome}");
        if ($outcome === 'failed') {
            $this->status(force: true); // a login or limit problem shows in the panel right away
            $this->connections->heartbeat($connection->id, $this->status);
        }

        return $outcome;
    }
}

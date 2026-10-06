<?php

namespace Tests\Support;

use App\Arkon\Ai\AiCompletion;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiRequest;
use App\Arkon\Ai\ClaudeRunner;
use App\Arkon\Ai\RunnerStatus;
use Closure;

/**
 * A scripted stand-in for Claude Code in tests: each run takes the next reply (a proposal
 * array, a raw value, an AiException, or a closure called with the request and keepGoing).
 * It never starts a process and never uses a real login.
 */
final class FakeClaudeRunner implements ClaudeRunner
{
    /** @var list<AiRequest> */
    public array $requests = [];

    /** @param list<mixed> $replies */
    public function __construct(private array $replies = [], private ?RunnerStatus $status = null) {}

    public static function ready(): RunnerStatus
    {
        return new RunnerStatus(true, null, 'Claude Code 2.1.292, signed in with a Claude Pro subscription.', '2.1.292', 'claude.ai', 'pro');
    }

    public function push(mixed ...$replies): self
    {
        array_push($this->replies, ...$replies);

        return $this;
    }

    public function check(): RunnerStatus
    {
        return $this->status ?? self::ready();
    }

    public function run(AiRequest $request, callable $keepGoing): AiCompletion
    {
        $this->requests[] = $request;
        if ($this->replies === []) {
            throw new \LogicException('FakeClaudeRunner has no reply left');
        }
        $reply = array_shift($this->replies);
        if ($reply instanceof Closure) {
            $reply = $reply($request, $keepGoing);
        }
        if ($reply instanceof AiException) {
            throw $reply;
        }

        return new AiCompletion($reply, json_encode($reply));
    }
}

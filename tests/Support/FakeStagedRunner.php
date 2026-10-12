<?php

namespace Tests\Support;

use App\Arkon\Ai\AiCompletion;
use App\Arkon\Ai\AiException;
use App\Arkon\Ai\AiRequest;
use App\Arkon\Ai\PendingRun;
use App\Arkon\Ai\RunnerStatus;
use App\Arkon\Ai\StagedRunner;
use Closure;

/**
 * A provider stand-in for staged website runs: each stage is answered by the first rule whose label
 * prefix matches (a reply array, an AiException, or a closure of the request). A reply can take some
 * polls ($polls) so stages overlap like real processes. Records every request, the most stages running
 * at once, and which runs were stopped. Never starts a process.
 */
final class FakeStagedRunner implements StagedRunner
{
    /** @var list<AiRequest> */
    public array $requests = [];

    public int $running = 0;

    public int $maxRunning = 0;

    /** @var list<string> */
    public array $closed = [];

    /** @param array<string, mixed> $rules label prefix => reply */
    public function __construct(private array $rules, private int $polls = 3) {}

    public function check(): RunnerStatus
    {
        return FakeClaudeRunner::ready();
    }

    public function run(AiRequest $request, callable $keepGoing): AiCompletion
    {
        $run = $this->start($request, $keepGoing);
        while (($done = $run->poll()) === null) {
        }

        return $done;
    }

    public function start(AiRequest $request, callable $keepGoing): PendingRun
    {
        $this->requests[] = $request;
        $reply = null;
        foreach ($this->rules as $prefix => $r) {
            if (str_starts_with($request->label, $prefix)) {
                $reply = $r;
                break;
            }
        }
        if ($reply === null) {
            throw new \LogicException('No fake reply for stage '.$request->label);
        }
        $this->running++;
        $this->maxRunning = max($this->maxRunning, $this->running);

        return new class($this, $request, $reply, $keepGoing, $this->polls) implements PendingRun
        {
            private bool $done = false;

            public function __construct(private FakeStagedRunner $owner, private AiRequest $request, private mixed $reply, private $keepGoing, private int $polls) {}

            public function poll(): ?AiCompletion
            {
                if (! ($this->keepGoing)()) {
                    $this->close();
                    throw new AiException(AiException::CANCELLED, 'The request was cancelled.');
                }
                if (--$this->polls > 0) {
                    if ($this->request->onEvent) {
                        ($this->request->onEvent)(['provider' => 'fake', 'type' => 'output']);
                    }

                    return null;
                }
                $this->finish();
                $reply = $this->reply instanceof Closure ? ($this->reply)($this->request) : $this->reply;
                if ($reply instanceof AiException) {
                    throw $reply;
                }

                return new AiCompletion($reply, json_encode($reply), 'fake', ['output_tokens' => 1]);
            }

            public function close(): void
            {
                if (! $this->done) {
                    $this->owner->closed[] = $this->request->label;
                    $this->finish();
                }
            }

            public function diagnostics(): array
            {
                return ['stage' => $this->request->label];
            }

            private function finish(): void
            {
                if (! $this->done) {
                    $this->done = true;
                    $this->owner->running--;
                }
            }
        };
    }

    /** @return list<string> */
    public function labels(): array
    {
        return array_map(fn ($r) => $r->label, $this->requests);
    }
}

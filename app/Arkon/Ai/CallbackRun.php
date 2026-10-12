<?php

namespace App\Arkon\Ai;

/** A started CLI generation whose reply is read by a callback when the process ends (providers without an event stream). */
final class CallbackRun implements PendingRun
{
    private bool $closed = false;

    /**
     * @param  \Closure(array{code: int, out: string, err: string}): AiCompletion  $finish
     * @param  \Closure(): void  $cleanup
     */
    public function __construct(private readonly CliRun $run, private readonly \Closure $finish, private readonly \Closure $cleanup, private readonly RunTrace $trace, private readonly string $label) {}

    public function poll(): ?AiCompletion
    {
        try {
            $result = $this->run->poll();
        } catch (AiException $e) {
            $this->trace->finish($e->code(), null, $this->run->stderr());
            $this->close();
            throw $e;
        }
        if ($result === null) {
            return null;
        }
        try {
            $completion = ($this->finish)($result);
            $this->trace->finish('completed', $result['code'], '', $completion->usage);

            return $completion;
        } catch (AiException $e) {
            $this->trace->finish($e->code(), $result['code'], $result['err']);
            throw $e;
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            $this->run->close();
            ($this->cleanup)();
        }
    }

    public function diagnostics(): array
    {
        return ['stage' => $this->label, 'elapsed' => round($this->trace->elapsed(), 1), 'pid' => $this->run->pid()];
    }
}

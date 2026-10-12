<?php

namespace App\Arkon\Ai;

/** A provider run that was started and is polled (so independent stages can run side by side). */
interface PendingRun
{
    /** Null while running; the completion when done. Throws AiException when the run fails or is stopped. */
    public function poll(): ?AiCompletion;

    /** Stops the run (whole process tree) if it still runs and cleans up. Safe to call more than once. */
    public function close(): void;

    /** What happened so far, for diagnostics: elapsed, first output, longest silence, recent events. */
    public function diagnostics(): array;
}

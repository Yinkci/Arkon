<?php

namespace App\Arkon\Ai;

/** A runner that can start a generation without waiting for it (local CLI providers). */
interface StagedRunner extends AiRunner
{
    public function start(AiRequest $request, callable $keepGoing): PendingRun;
}

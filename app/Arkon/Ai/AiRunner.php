<?php

namespace App\Arkon\Ai;

interface AiRunner
{
    public function check(): RunnerStatus;

    /** Generation only. Results are untrusted until Arkon compiles them. */
    public function run(AiRequest $request, callable $keepGoing): AiCompletion;
}

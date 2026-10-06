<?php

namespace App\Arkon\Ai;

/**
 * Executes one generation with Claude Code. The only implementation used by the app is
 * ClaudeCodeCli (the official CLI under the user's own login); tests use fakes.
 */
interface ClaudeRunner
{
    /** Whether Claude Code can run here, and with which login (never credentials). */
    public function check(): RunnerStatus;

    /**
     * @param  callable(): bool  $keepGoing  polled while Claude works; false stops the run (cancelled, lease lost)
     *
     * @throws AiException CLAUDE_* codes, CANCELLED, INVALID_OUTPUT (no structured output)
     */
    public function run(AiRequest $request, callable $keepGoing): AiCompletion;
}

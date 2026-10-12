<?php

namespace App\Arkon\Ai;

/**
 * One generation: instructions, the prompt (context + request) and the reply's JSON schema. A staged run
 * gives each request its own time limit (seconds; null uses the provider's default) and a label that names
 * the stage in progress reports and diagnostics.
 */
final class AiRequest
{
    public function __construct(
        public readonly string $instructions,
        public readonly string $prompt,
        public readonly array $schema,
        public readonly ?\Closure $onEvent = null,
        public readonly ?int $timeout = null,
        public readonly string $label = 'generation',
    ) {}
}

<?php

namespace App\Arkon\Ai;

/** One generation: instructions, the prompt (page context + request) and the reply's JSON schema. */
final class AiRequest
{
    public function __construct(
        public readonly string $instructions,
        public readonly string $prompt,
        public readonly array $schema,
    ) {}
}

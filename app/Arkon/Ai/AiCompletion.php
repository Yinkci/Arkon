<?php

namespace App\Arkon\Ai;

/** A generation's reply: the structured output (untrusted until compiled) and its JSON text. */
final class AiCompletion
{
    public function __construct(
        public readonly mixed $output,
        public readonly string $text,
        public readonly ?string $provider = null,
        public readonly array $usage = [],
    ) {}
}

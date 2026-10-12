<?php

namespace App\Arkon\Ai\Images;

/** One generated image as the provider delivered it: raw bytes (validated later) and what it reported. */
final class GeneratedImage
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $provider,
        public readonly ?string $revisedPrompt = null,
        public readonly array $usage = [],
    ) {}
}

<?php

namespace App\Arkon\Renderer;

use RuntimeException;

class RenderException extends RuntimeException
{
    /** @param list<array{nodeId?: string, path?: string, message: string}> $issues */
    public function __construct(string $message, public readonly array $issues)
    {
        parent::__construct($message);
    }
}

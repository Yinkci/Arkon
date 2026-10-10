<?php

namespace App\Arkon\Errors;

class RateLimitedException extends ArkonException
{
    public function __construct(string $message, public readonly int $retryAfter = 60)
    {
        parent::__construct($message);
    }

    public function code(): string
    {
        return 'RATE_LIMITED';
    }

    public function status(): int
    {
        return 429;
    }
}

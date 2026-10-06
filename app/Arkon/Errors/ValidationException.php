<?php

namespace App\Arkon\Errors;

class ValidationException extends ArkonException
{
    /** @param list<array{nodeId?: string, path?: string, message: string}> $issues */
    public function __construct(string $message, public readonly array $issues = [])
    {
        parent::__construct($message);
    }

    public function code(): string
    {
        return 'VALIDATION';
    }

    public function status(): int
    {
        return 422;
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'issues' => $this->issues];
    }
}

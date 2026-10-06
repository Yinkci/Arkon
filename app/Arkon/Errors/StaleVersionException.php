<?php

namespace App\Arkon\Errors;

class StaleVersionException extends ArkonException
{
    public function __construct(public readonly int $expectedVersion, public readonly int $currentVersion)
    {
        parent::__construct("This page changed since you loaded it (you have version {$expectedVersion}, current is {$currentVersion})");
    }

    public function code(): string
    {
        return 'STALE_VERSION';
    }

    public function status(): int
    {
        return 409;
    }

    public function toArray(): array
    {
        return [...parent::toArray(), 'currentVersion' => $this->currentVersion];
    }
}

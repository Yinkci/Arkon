<?php

namespace App\Arkon\Errors;

class ForbiddenException extends ArkonException
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct("You do not have permission to do this ({$permission})");
    }

    public function code(): string
    {
        return 'FORBIDDEN';
    }

    public function status(): int
    {
        return 403;
    }
}

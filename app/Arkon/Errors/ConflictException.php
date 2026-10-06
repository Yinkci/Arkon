<?php

namespace App\Arkon\Errors;

class ConflictException extends ArkonException
{
    public function code(): string
    {
        return 'CONFLICT';
    }

    public function status(): int
    {
        return 409;
    }
}

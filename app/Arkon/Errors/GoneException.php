<?php

namespace App\Arkon\Errors;

/** The resource exists but no longer accepts this request (a form that stopped accepting submissions). */
class GoneException extends ArkonException
{
    public function code(): string
    {
        return 'GONE';
    }

    public function status(): int
    {
        return 410;
    }
}

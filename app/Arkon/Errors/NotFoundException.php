<?php

namespace App\Arkon\Errors;

/** Also used for resources of other sites, so responses never reveal they exist. */
class NotFoundException extends ArkonException
{
    public function __construct(string $what)
    {
        parent::__construct("{$what} not found");
    }

    public function code(): string
    {
        return 'NOT_FOUND';
    }

    public function status(): int
    {
        return 404;
    }
}

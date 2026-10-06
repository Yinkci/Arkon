<?php

namespace App\Arkon\Schema;

use RuntimeException;

class OperationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $operationIndex)
    {
        parent::__construct($message);
    }
}

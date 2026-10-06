<?php

namespace App\Arkon\Errors;

use RuntimeException;

/** Domain errors with a stable code the editor acts on. */
abstract class ArkonException extends RuntimeException
{
    /** NOT_FOUND | FORBIDDEN | STALE_VERSION | VALIDATION | CONFLICT */
    abstract public function code(): string;

    abstract public function status(): int;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['ok' => false, 'code' => $this->code(), 'message' => $this->getMessage()];
    }
}

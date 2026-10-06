<?php

namespace App\Arkon\Database;

use RuntimeException;

class ConfigurationException extends RuntimeException
{
    /** @param list<string> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct("Invalid configuration:\n- ".implode("\n- ", $problems));
    }
}

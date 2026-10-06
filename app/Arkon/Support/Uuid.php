<?php

namespace App\Arkon\Support;

use Illuminate\Support\Str;

final class Uuid
{
    /** RFC 9562 UUIDv7: time-ordered, index-friendly. */
    public static function v7(): string
    {
        return (string) Str::uuid7();
    }

    /** Ids from URLs and request bodies are checked before they reach a uuid column. */
    public static function isValid(mixed $value): bool
    {
        return Rules::matches('uuid', $value);
    }
}

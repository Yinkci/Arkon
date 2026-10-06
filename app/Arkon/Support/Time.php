<?php

namespace App\Arkon\Support;

use Illuminate\Support\Carbon;

final class Time
{
    /** Database timestamps as ISO 8601 (UTC, milliseconds) for JSON and the browser. */
    public static function iso(mixed $value): ?string
    {
        return $value === null ? null : Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}

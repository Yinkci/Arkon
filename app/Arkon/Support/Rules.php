<?php

namespace App\Arkon\Support;

use RuntimeException;

/**
 * The shared rules in resources/arkon/rules.json (also imported by the editor).
 */
final class Rules
{
    private static ?array $rules = null;

    /** @var array<string, string> */
    private static array $compiled = [];

    public static function all(): array
    {
        if (self::$rules === null) {
            $path = resource_path('arkon/rules.json');
            $rules = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($rules)) {
                throw new RuntimeException("Invalid rules file {$path}");
            }
            self::$rules = $rules;
        }

        return self::$rules;
    }

    public static function get(string $key): mixed
    {
        return data_get(self::all(), $key);
    }

    /** Matches a shared pattern. `D`: `$` only at the very end, exactly like JavaScript. */
    public static function matches(string $pattern, mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        $regex = self::$compiled[$pattern] ??= '/'.str_replace('/', '\/', (string) self::get("patterns.{$pattern}")).'/Du';

        return preg_match($regex, $value) === 1;
    }

    public static function message(string $key, array $values = []): string
    {
        return Text::format((string) self::get("messages.{$key}"), $values);
    }
}

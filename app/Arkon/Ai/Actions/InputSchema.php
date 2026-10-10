<?php

namespace App\Arkon\Ai\Actions;

use App\Arkon\Errors\ValidationException;

/**
 * Checks tool arguments against an action's JSON schema before anything runs: the subset the
 * actions use (object with properties, required and additionalProperties false; string with
 * maxLength, pattern and enum; integer; boolean; array with items and maxItems; nullable types).
 * The domain services still validate the values; this rejects malformed calls early with the
 * exact path, so a model can repair its call.
 */
final class InputSchema
{
    public static function check(array $schema, mixed $value, string $path = 'arguments'): void
    {
        $types = (array) ($schema['type'] ?? []);
        if ($value === null && in_array('null', $types, true)) {
            return;
        }
        $type = array_values(array_diff($types, ['null']))[0] ?? null;
        $fail = fn (string $message) => throw new ValidationException("{$path}: {$message}", [['path' => $path, 'message' => $message]]);
        match ($type) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)) ?: $fail('expected an object'),
            'array' => is_array($value) && array_is_list($value) ?: $fail('expected a list'),
            'string' => is_string($value) ?: $fail('expected text'),
            'integer' => is_int($value) ?: $fail('expected an integer'),
            'boolean' => is_bool($value) ?: $fail('expected true or false'),
            default => null,
        };
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $fail('expected one of '.implode(', ', array_map('json_encode', $schema['enum'])));
        }
        if ($type === 'string') {
            if (isset($schema['maxLength']) && mb_strlen($value) > $schema['maxLength']) {
                $fail("at most {$schema['maxLength']} characters");
            }
            if (isset($schema['pattern']) && ! preg_match('/'.$schema['pattern'].'/u', $value)) {
                $fail('does not match the expected format');
            }
        }
        if ($type === 'array') {
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $fail("at most {$schema['maxItems']} items");
            }
            foreach ($value as $i => $item) {
                if (isset($schema['items'])) {
                    self::check($schema['items'], $item, "{$path}.{$i}");
                }
            }
        }
        if ($type === 'object') {
            $properties = (array) ($schema['properties'] ?? []);
            foreach ($schema['required'] ?? [] as $key) {
                array_key_exists($key, $value) ?: $fail("{$key} is required");
            }
            foreach ($value as $key => $item) {
                if (! isset($properties[$key])) {
                    if (($schema['additionalProperties'] ?? true) === false) {
                        $fail("unknown argument {$key}");
                    }

                    continue;
                }
                self::check((array) $properties[$key], $item, "{$path}.{$key}");
            }
        }
    }
}

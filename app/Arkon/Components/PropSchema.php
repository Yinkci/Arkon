<?php

namespace App\Arkon\Components;

use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;
use App\Arkon\Support\Text;
use InvalidArgumentException;

/**
 * Interpreter for the small prop-schema language used by component manifests.
 * The TypeScript twin is resources/js/arkon/components/props.ts.
 *
 * Field types: string {maxLength, minLength}, link {maxLength} (a string
 * matching the shared safe-link pattern), enum {values}, boolean, uuid,
 * object {properties, nullable}. A field without `default` is required. Objects
 * are strict: unknown keys are rejected.
 */
final class PropSchema
{
    /** @param array<string, array> $fields */
    public function __construct(private readonly array $fields) {}

    /**
     * Validates props and returns them with defaults applied. Raw form (decoded
     * request JSON) distinguishes `{}` from `[]`; internal form (stored documents)
     * uses arrays for every object.
     *
     * @return array{0: array|null, 1: list<array{path: string, message: string}>} [parsed, issues]
     */
    public function parse(mixed $props, bool $raw = true): array
    {
        $issues = [];
        $parsed = self::parseObject($this->fields, $props, '', $issues, $raw);

        return [$issues === [] ? $parsed : null, $issues];
    }

    /** Parses props that are known to be valid (throws otherwise). */
    public function parseValid(mixed $props): array
    {
        [$parsed, $issues] = $this->parse($props);
        if ($parsed === null) {
            throw new InvalidArgumentException('Invalid props: '.implode('; ', array_column($issues, 'message')));
        }

        return $parsed;
    }

    private static function parseObject(array $fields, mixed $value, string $at, array &$issues, bool $raw): ?array
    {
        if (! Json::isObject($value) && ($raw || $value !== [])) {
            $issues[] = ['path' => $at, 'message' => Rules::message('expectedObject')];

            return null;
        }
        $entries = Json::entries($value);
        foreach (array_keys($entries) as $key) {
            if (! array_key_exists((string) $key, $fields)) {
                $issues[] = ['path' => self::join($at, (string) $key), 'message' => Rules::message('unrecognizedKey', ['key' => $key])];
            }
        }
        $out = [];
        foreach ($fields as $key => $field) {
            $path = self::join($at, $key);
            if (! array_key_exists($key, $entries)) {
                if (array_key_exists('default', $field)) {
                    $out[$key] = $field['default'];
                } else {
                    $issues[] = ['path' => $path, 'message' => Rules::message('required')];
                }

                continue;
            }
            $out[$key] = self::parseField($field, $entries[$key], $path, $issues, $raw);
        }

        return $out;
    }

    private static function parseField(array $field, mixed $value, string $at, array &$issues, bool $raw): mixed
    {
        switch ($field['type']) {
            case 'string':
                if (! is_string($value)) {
                    $issues[] = ['path' => $at, 'message' => Rules::message('expectedString')];

                    return null;
                }
                $length = Text::utf16Length($value);
                if (isset($field['maxLength']) && $length > $field['maxLength']) {
                    $issues[] = ['path' => $at, 'message' => Rules::message('tooLong', ['max' => $field['maxLength']])];
                }
                if (isset($field['minLength']) && $length < $field['minLength']) {
                    $issues[] = ['path' => $at, 'message' => Rules::message('tooShort', ['min' => $field['minLength']])];
                }

                return $value;

            case 'enum':
                if (! in_array($value, $field['values'], true)) {
                    $issues[] = ['path' => $at, 'message' => Rules::message('oneOf', ['values' => implode(', ', $field['values'])])];
                }

                return $value;

            case 'boolean':
                if (! is_bool($value)) {
                    $issues[] = ['path' => $at, 'message' => Rules::message('expectedBoolean')];
                }

                return $value;

            case 'link':
                // A string limited to safe destinations: relative paths, #fragments, ?queries,
                // http(s), mailto: and tel:. Never javascript:, data: or protocol-relative //host.
                if (! is_string($value)) {
                    $issues[] = ['path' => $at, 'message' => Rules::message('expectedString')];

                    return null;
                }
                if (isset($field['maxLength']) && Text::utf16Length($value) > $field['maxLength']) {
                    $issues[] = ['path' => $at, 'message' => Rules::message('tooLong', ['max' => $field['maxLength']])];
                } elseif (! Rules::matches('link', $value)) {
                    $issues[] = ['path' => $at, 'message' => Rules::message('unsafeLink')];
                }

                return $value;

            case 'uuid':
                if (! Rules::matches('uuid', $value)) {
                    $issues[] = ['path' => $at, 'message' => Rules::message('invalidUuid')];
                }

                return $value;

            case 'object':
                if ($value === null && ($field['nullable'] ?? false)) {
                    return null;
                }

                return self::parseObject($field['properties'] ?? [], $value, $at, $issues, $raw);
        }

        throw new InvalidArgumentException("Unknown prop type {$field['type']}");
    }

    private static function join(string $at, string $key): string
    {
        return $at === '' ? $key : "{$at}.{$key}";
    }
}

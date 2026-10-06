<?php

namespace App\Arkon\Support;

use stdClass;

/**
 * JSON with object/array fidelity.
 *
 * PHP decodes `{}` and `[]` to the same empty array, which would silently turn
 * an empty `props` or `seo` object into a list. Request bodies are therefore
 * decoded with empty objects kept as `stdClass` (the "raw" form validators see),
 * and documents are re-encoded schema-aware by DocumentCodec.
 */
final class Json
{
    /** Decodes with empty JSON objects preserved as stdClass and everything else as arrays. */
    public static function decode(string $json): mixed
    {
        return self::fromDecoded(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
    }

    private static function fromDecoded(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $vars = get_object_vars($value);

            return $vars === [] ? new stdClass : array_map(self::fromDecoded(...), $vars);
        }
        if (is_array($value)) {
            return array_map(self::fromDecoded(...), $value);
        }

        return $value;
    }

    /** True for a JSON object in raw form (stdClass or a non-list array). */
    public static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && $value !== [] && ! array_is_list($value));
    }

    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /** @return array<string|int, mixed> the members of a raw object */
    public static function entries(mixed $value): array
    {
        return $value instanceof stdClass ? get_object_vars($value) : (is_array($value) ? $value : []);
    }

    /** Raw form → internal form: every object becomes an array (empty objects included). */
    public static function toArray(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            return array_map(self::toArray(...), get_object_vars($value));
        }
        if (is_array($value)) {
            return array_map(self::toArray(...), $value);
        }

        return $value;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** JSON with object keys sorted, so equal requests always hash equally. */
    public static function canonical(mixed $value): string
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
            if ($value === []) {
                return '{}';
            }
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map(self::canonical(...), $value)).']';
            }
            $keys = array_map('strval', array_keys($value));
            $pairs = array_combine($keys, array_values($value));
            ksort($pairs, SORT_STRING);
            $out = [];
            foreach ($pairs as $key => $item) {
                $out[] = self::encode((string) $key).':'.self::canonical($item);
            }

            return '{'.implode(',', $out).'}';
        }

        return self::encode($value);
    }
}

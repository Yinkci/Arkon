<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Checks JSON data against a schema of the OpenAPI document: $ref, oneOf, type (with null),
 * required, properties, additionalProperties, items and enum. Enough for contract tests of the
 * response shapes; formats are documentation only.
 */
final class OpenApiSchema
{
    public static function assert(array $spec, array $schema, mixed $data, string $path = '$'): void
    {
        if (isset($schema['$ref'])) {
            $name = substr($schema['$ref'], strlen('#/components/schemas/'));
            self::assert($spec, $spec['components']['schemas'][$name] ?? Assert::fail("Unknown schema {$name}"), $data, $path);

            return;
        }
        if (isset($schema['oneOf'])) {
            foreach ($schema['oneOf'] as $option) {
                try {
                    self::assert($spec, $option, $data, $path);

                    return;
                } catch (AssertionFailedError) {
                }
            }
            Assert::fail("{$path}: matches none of the documented shapes");
        }
        $types = (array) ($schema['type'] ?? []);
        if ($types !== []) {
            $actual = match (true) {
                $data === null => 'null', is_bool($data) => 'boolean', is_int($data) => 'integer', is_float($data) => 'number', is_string($data) => 'string',
                is_array($data) && array_is_list($data) && $data !== [] => 'array', is_array($data) => $data === [] ? 'empty' : 'object', default => 'unknown',
            };
            $ok = in_array($actual, $types, true) || ($actual === 'integer' && in_array('number', $types, true))
                || ($actual === 'empty' && (in_array('array', $types, true) || in_array('object', $types, true)));
            Assert::assertTrue($ok, "{$path}: expected ".implode('|', $types).", got {$actual}");
        }
        if (isset($schema['enum']) && $data !== null) {
            Assert::assertContains($data, $schema['enum'], "{$path}: not one of the documented values");
        }
        if (is_array($data) && ! array_is_list($data) || ($data === [] && in_array('object', $types, true))) {
            foreach ($schema['required'] ?? [] as $key) {
                Assert::assertArrayHasKey($key, $data, "{$path}.{$key} is documented as always present");
            }
            foreach ($data as $key => $value) {
                if (isset($schema['properties'][$key])) {
                    self::assert($spec, $schema['properties'][$key], $value, "{$path}.{$key}");
                } elseif (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
                    self::assert($spec, $schema['additionalProperties'], $value, "{$path}.{$key}");
                } elseif (isset($schema['properties'])) {
                    Assert::fail("{$path}.{$key} is not documented");
                }
            }
        }
        if (is_array($data) && array_is_list($data) && isset($schema['items'])) {
            foreach ($data as $i => $item) {
                self::assert($spec, $schema['items'], $item, "{$path}[{$i}]");
            }
        }
    }
}

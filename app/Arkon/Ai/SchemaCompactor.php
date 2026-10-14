<?php

namespace App\Arkon\Ai;

/**
 * compact(): share identical structured-output rules; never remove or weaken constraints.
 * loosenToFit(): only for a command-line transport that cannot carry the full schema (Claude Code on
 * Windows); it relaxes the model's guide, never Arkon's validation of the reply.
 */
final class SchemaCompactor
{
    public static function compact(array $schema): array
    {
        $counts = [];
        $shared = [];
        $key = static function (array $value): ?string {
            if (! is_string($value['type'] ?? null) || isset($value['$defs'])) {
                return null;
            }
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return strlen($json) >= 140 ? hash('sha256', $json) : null;
        };
        $count = function (mixed $value) use (&$count, &$counts, $key): void {
            if (! is_array($value)) {
                return;
            }$hash = $key($value);
            if ($hash !== null) {
                $counts[$hash] = ($counts[$hash] ?? 0) + 1;
            }foreach ($value as $child) {
                $count($child);
            }
        };
        $count($schema);
        $rewrite = function (mixed $value, bool $factor = true) use (&$rewrite, &$shared, $counts, $key): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $hash = $key($value);
            if ($factor && $hash !== null && ($counts[$hash] ?? 0) > 1) {
                $name = 'shared_'.substr($hash, 0, 24);
                if (! isset($shared[$name])) {
                    $shared[$name] = $rewrite($value, false);
                }

                return ['$ref' => '#/$defs/'.$name];
            }
            foreach ($value as $name => $child) {
                $value[$name] = $rewrite($child);
            }

            return $value;
        };
        $result = $rewrite($schema);
        $result['$defs'] = [...($result['$defs'] ?? []), ...$shared];

        // Definition identifiers are transport names, not component/field names.
        // Short aliases leave every user field and validation rule unchanged.
        $names = [];
        foreach (array_keys($result['$defs']) as $index => $name) {
            $names[$name] = 'r'.base_convert((string) $index, 10, 36);
        }
        $alias = function (mixed $value) use (&$alias, $names): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (isset($value['$ref']) && str_starts_with($value['$ref'], '#/$defs/')) {
                $parts = explode('/', substr($value['$ref'], strlen('#/$defs/')), 2);
                $name = str_replace(['~1', '~0'], ['/', '~'], $parts[0]);
                if (isset($names[$name])) {
                    $value['$ref'] = '#/$defs/'.$names[$name].(isset($parts[1]) ? '/'.$parts[1] : '');
                }
            }
            foreach ($value as $name => $child) {
                $value[$name] = $alias($child);
            }

            return $value;
        };
        $result = $alias($result);
        $defs = [];
        foreach ($result['$defs'] as $name => $definition) {
            $defs[$names[$name]] = $definition;
        }
        // JSON Schema requires $defs to be an object: with nothing shared there is no $defs (an empty PHP array would encode as a list).
        if ($defs === []) {
            unset($result['$defs']);
        } else {
            $result['$defs'] = $defs;
        }

        return $result;
    }

    /**
     * The length of a Windows command line for these arguments as PHP's proc_open builds it: each
     * argument is quoted and every double quote (and backslash before one) is escaped, so a JSON
     * schema costs about one extra character per quote. Errs on the long side.
     */
    public static function windowsCommandLength(array $argv): int
    {
        $length = 0;
        foreach ($argv as $arg) {
            $arg = (string) $arg;
            $length += strlen($arg) + substr_count($arg, '"') + substr_count($arg, '\\') + 3;
        }

        return $length;
    }

    /**
     * A structural guide for a model whose schema must fit a command line: the largest block property
     * schemas (objects shaped {type: const, props: {...}}) become plain objects, largest first, until
     * $fits accepts the schema. Block types, actions and every other part stay exact, the instructions
     * still list each block's properties, and Arkon's compiler validates every proposal as before, so
     * nothing invalid is accepted. Returns null when even the loosest guide does not fit.
     *
     * @param  callable(array): bool  $fits
     */
    /**
     * How many block property schemas were relaxed between the compacted schema and the one that
     * fitted: zero when the whole guide was carried, which is what should normally happen.
     */
    public static function loosenedCount(array $compact, array $sent): int
    {
        $plain = function (mixed $node) use (&$plain): int {
            if (! is_array($node)) {
                return 0;
            }
            $count = $node === ['type' => 'object'] ? 1 : 0;
            foreach ($node as $child) {
                $count += $plain($child);
            }

            return $count;
        };

        return max(0, $plain($sent) - $plain($compact));
    }

    public static function loosenToFit(array $schema, callable $fits): ?array
    {
        if ($fits($schema)) {
            return $schema;
        }
        $paths = [];
        $find = function (mixed $node, array $path) use (&$find, &$paths): void {
            if (! is_array($node)) {
                return;
            }
            if (isset($node['properties']['type']['const'], $node['properties']['props']) && ($node['properties']['props']['type'] ?? null) === 'object' && isset($node['properties']['props']['properties'])) {
                $paths[] = [...$path, 'properties', 'props'];
            }
            foreach ($node as $key => $child) {
                $find($child, [...$path, $key]);
            }
        };
        $find($schema, []);
        $size = fn (array $path) => strlen((string) json_encode(self::at($schema, $path)));
        usort($paths, fn ($a, $b) => $size($b) <=> $size($a));
        foreach ($paths as $path) {
            $schema = self::put($schema, $path, ['type' => 'object']);
            if ($fits($schema)) {
                return $schema;
            }
        }

        return null;
    }

    private static function at(array $node, array $path): mixed
    {
        foreach ($path as $key) {
            $node = $node[$key];
        }

        return $node;
    }

    private static function put(array $node, array $path, mixed $value): array
    {
        $key = array_shift($path);
        $node[$key] = $path === [] ? $value : self::put($node[$key], $path, $value);

        return $node;
    }
}

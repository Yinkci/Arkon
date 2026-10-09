<?php

namespace App\Arkon\Ai;

/** Share identical structured-output rules; never remove or weaken constraints. */
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
        $result['$defs'] = $defs;

        return $result;
    }
}

<?php

namespace App\Arkon\Ai;

/**
 * Orders a reply schema so the model writes its long parts last.
 *
 * Under a strict JSON schema the model writes properties in schema order. When a required property follows a long
 * list (a page's `proposal` with its `changes`, then `seo`), the model tends to finish the list and try to close the
 * object; the schema forbids that until the remaining property is written, and the reply degenerates into whitespace
 * without end. Reproduced October 2026: a Home page step ran away 3 of 4 times, always right after `…}]}` with `seo`
 * still due; with `proposal` last it finished 6 of 6 times (see docs/ARCHITECTURE.md).
 *
 * Rule: in every object schema (and every `$defs` entry), bulky properties — a subschema over BULKY bytes, a `$ref`
 * counting as the schema it names — move after the others; everything else keeps its authored order. Only bulk moves:
 * fully sorting by size also reordered small keys (`parent` before `action`, `ref` after `index`) and made the model
 * mislabel the blocks it adds (3 of 10 real runs failed the builder checks). Required lists and meaning are unchanged;
 * the reply is read by key, so the order matters only to how the model writes it.
 */
final class SchemaOrder
{
    public const BULKY = 1500;

    public static function tailHeavy(array $schema): array
    {
        $weights = [];
        foreach ($schema['$defs'] ?? [] as $name => $def) {
            $weights['#/$defs/'.$name] = strlen((string) json_encode($def));
        }
        $order = function (mixed $node) use (&$order, $weights): mixed {
            if (! is_array($node)) {
                return $node;
            }
            foreach ($node as $key => $value) {
                if ($key !== 'properties') {
                    $node[$key] = $order($value);
                }
            }
            if (isset($node['properties']) && is_array($node['properties']) && ! array_is_list($node['properties'])) {
                $props = array_map($order, $node['properties']);
                $bulky = fn (mixed $p) => (is_array($p) && is_string($p['$ref'] ?? null) ? ($weights[$p['$ref']] ?? 0) : strlen((string) json_encode($p))) > self::BULKY;
                $node['properties'] = [...array_filter($props, fn ($p) => ! $bulky($p)), ...array_filter($props, $bulky)];
            }

            return $node;
        };

        return $order($schema);
    }
}

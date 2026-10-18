<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;

/**
 * What a change to a set of field definitions would do to the values already stored under them.
 *
 * Used by content types and by field groups alike, because a field is a field wherever it was
 * declared, and the same edit means the same thing to an entry either way.
 */
final class FieldChanges
{
    /**
     * Fields that were there and are not any more: their values stay in storage but nothing can
     * read them, so the caller is expected to ask before going ahead.
     *
     * @return list<array<string, mixed>>
     */
    public static function removed(array $before, array $after): array
    {
        $keys = array_column($after, 'key');

        return array_values(array_filter($before, fn ($field) => ! in_array($field['key'], $keys, true)));
    }

    /**
     * Fields that kept their key but changed into something that cannot hold what they held: a
     * group turned into text, a repeater into a number. There is no sensible reading of the old
     * value as the new type, so this is refused rather than quietly dropped — the way through is
     * to remove the field and add a new one, which says plainly that the values are being left
     * behind.
     */
    public static function assertTypesCompatible(array $before, array $after, ?string $topKey = null, string $where = ''): void
    {
        $was = array_column($before, null, 'key');
        foreach ($after as $field) {
            $old = $was[$field['key']] ?? null;
            if ($old === null) {
                continue;
            }
            $top = $topKey ?? $field['key'];
            $label = $where.$field['label'];
            if ($old['type'] !== $field['type'] && self::shape($old['type']) !== self::shape($field['type'])) {
                throw new ValidationException(
                    "{$label} cannot change from ".strtolower(FieldTypes::get($old['type'])['label'])
                        .' to '.strtolower(FieldTypes::get($field['type'])['label'])
                        .', because what entries stored in it cannot be read as the new kind. Remove the field and add a new one instead.',
                    [[
                        'path' => 'fields.'.$top.'.type',
                        'fieldKey' => $top,
                        'code' => 'incompatible_type_change',
                        'message' => 'Incompatible field type change',
                    ]],
                );
            }
            // The fields inside a group, a repeater or each layout are fields too, with values
            // of their own stored inside the outer one.
            self::assertTypesCompatible($old['fields'] ?? [], $field['fields'] ?? [], $top, $label.' → ');
            $layouts = array_column($old['layouts'] ?? [], null, 'key');
            foreach ($field['layouts'] ?? [] as $layout) {
                if (isset($layouts[$layout['key']])) {
                    self::assertTypesCompatible($layouts[$layout['key']]['fields'], $layout['fields'], $top, $label.' → '.$layout['label'].' → ');
                }
            }
        }
    }

    /**
     * Fields removed from inside a group, a repeater or a layout of a field that is itself kept.
     * Their values sit inside the outer field's value and are dropped the next time an entry
     * saves it, so removing one needs the same confirmation as removing a field.
     *
     * @return list<array{key: string, label: string, fieldKey: string}>
     */
    public static function removedNested(array $before, array $after): array
    {
        $now = array_column($after, null, 'key');
        $out = [];
        foreach ($before as $field) {
            $kept = $now[$field['key']] ?? null;
            if ($kept === null) {
                continue;
            }
            foreach (self::removed($field['fields'] ?? [], $kept['fields'] ?? []) as $child) {
                $out[] = ['key' => $field['key'].'.'.$child['key'], 'label' => $field['label'].' → '.$child['label'], 'fieldKey' => $field['key']];
            }
            $layouts = array_column($kept['layouts'] ?? [], null, 'key');
            foreach ($field['layouts'] ?? [] as $layout) {
                if (! isset($layouts[$layout['key']])) {
                    continue; // The whole layout is gone: removedLayouts() reports that.
                }
                foreach (self::removed($layout['fields'], $layouts[$layout['key']]['fields']) as $child) {
                    $out[] = ['key' => $field['key'].'.'.$layout['key'].'.'.$child['key'], 'label' => $field['label'].' → '.$layout['label'].' → '.$child['label'], 'fieldKey' => $field['key']];
                }
            }
        }

        return $out;
    }

    /**
     * Fields kept under the same key whose definition changed in a way stored values may not
     * survive: a new type, fewer choices, a shorter limit, a pattern, another target. Only these
     * need the entries' values checked; renaming a label changes nothing stored.
     *
     * @return list<array<string, mixed>> the new definitions of those fields
     */
    public static function reshaped(array $before, array $after): array
    {
        $was = array_column($before, null, 'key');
        $out = [];
        foreach ($after as $field) {
            $old = $was[$field['key']] ?? null;
            if ($old !== null && self::comparable($old) !== self::comparable($field)) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /** A field without what a person reads (labels, help, placeholders), in a stable order. */
    private static function comparable(array $field): string
    {
        $strip = function (mixed $node) use (&$strip): mixed {
            if (! is_array($node)) {
                return $node;
            }
            if (! array_is_list($node)) {
                unset($node['label'], $node['help'], $node['placeholder'], $node['rowLabel'], $node['addLabel'], $node['unit']);
                ksort($node);
            }

            return array_map($strip, $node);
        };
        // Choices are compared by value only: a reworded choice keeps what entries stored.
        $field['choices'] = array_column($field['choices'] ?? [], 'value');

        return json_encode($strip($field));
    }

    /**
     * Layouts a content-sections field no longer offers, with the field they belonged to. The
     * blocks that use them are kept, so this is a warning rather than a loss — but somebody
     * should be told before the sections stop being rendered.
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<array{fieldKey: string, fieldLabel: string, layout: string, layoutLabel: string}>
     */
    public static function removedLayouts(array $before, array $after): array
    {
        $now = array_column($after, null, 'key');
        $out = [];
        foreach ($before as $field) {
            if (($field['type'] ?? null) !== 'flexible') {
                continue;
            }
            $kept = array_column($now[$field['key']]['layouts'] ?? [], 'key');
            foreach ($field['layouts'] ?? [] as $layout) {
                if (! in_array($layout['key'], $kept, true)) {
                    $out[] = ['fieldKey' => $field['key'], 'fieldLabel' => $field['label'], 'layout' => $layout['key'], 'layoutLabel' => $layout['label']];
                }
            }
        }

        return $out;
    }

    /**
     * The shapes a stored value can have. Within one shape a change is a reinterpretation the
     * field engine can make sense of; across shapes it is not.
     */
    private static function shape(string $type): string
    {
        return match (FieldTypes::store($type)) {
            'object' => 'object',
            'rows' => 'rows',
            // A reference is an id with a meaning attached to it: text read as a reference points
            // at nothing, and a reference read as text is an id nobody typed.
            'media', 'mediaList' => 'media',
            'refs' => 'refs',
            // A section says which layout it is; nothing else stores that, so nothing else can
            // be read as one.
            'blocks' => 'blocks',
            default => 'scalar',
        };
    }
}

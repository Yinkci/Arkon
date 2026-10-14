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
    public static function assertTypesCompatible(array $before, array $after): void
    {
        $was = array_column($before, null, 'key');
        foreach ($after as $field) {
            $old = $was[$field['key']] ?? null;
            if ($old === null || $old['type'] === $field['type']) {
                continue;
            }
            if (self::shape($old['type']) !== self::shape($field['type'])) {
                throw new ValidationException(
                    "{$field['label']} cannot change from ".strtolower(FieldTypes::get($old['type'])['label'])
                        .' to '.strtolower(FieldTypes::get($field['type'])['label'])
                        .', because what entries stored in it cannot be read as the new kind. Remove the field and add a new one instead.',
                    [[
                        'path' => 'fields.'.$field['key'].'.type',
                        'fieldKey' => $field['key'],
                        'code' => 'incompatible_type_change',
                        'message' => 'Incompatible field type change',
                    ]],
                );
            }
        }
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

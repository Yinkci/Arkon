<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Support\Json;
use App\Arkon\Support\Uuid;
use Illuminate\Support\Facades\DB;

/**
 * Content sections: an ordered sequence of blocks, each of a layout the field defines.
 *
 * This is structured editorial composition, not a second page builder. A layout is a named
 * shape with ordinary fields — the same field engine, the same validation, the same media and
 * relationships — and an author chooses which shapes to put in which order. Nothing here carries
 * spacing, colour or breakpoints: how a section looks belongs to the Builder template that
 * renders it.
 *
 * Changes are expressed as operations on one block (insert, move, update, remove, duplicate)
 * rather than as a whole new array. That is what makes an edit from the admin, from the API or
 * from AI a small, reviewable change, and what lets a revision say "added a Quote section"
 * instead of "the JSON changed".
 */
final class Flexible
{
    public const OPERATIONS = ['insertBlock', 'moveBlock', 'updateBlock', 'removeBlock', 'duplicateBlock'];

    /**
     * The content-sections fields in a set of field definitions, by key.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, array<string, mixed>>
     */
    public static function fieldsIn(array $fields): array
    {
        return array_column(array_filter($fields, fn ($field) => $field['type'] === 'flexible'), null, 'key');
    }

    /**
     * Applies one change to one content-sections field and gives back the field's whole value,
     * for the caller to save through FieldValues as usual — so an operation can never put
     * anything in storage that a straight save would have refused.
     *
     * @param  array<string, mixed>  $field  the field definition
     * @param  mixed  $value  the field's current value
     * @param  array<string, mixed>  $operation  {op, id?, index?, layout?, set?}
     * @return list<array<string, mixed>> the value afterwards
     */
    public static function apply(array $field, mixed $value, array $operation): array
    {
        $blocks = array_values(array_filter(Json::toArray($value) ?: [], 'is_array'));
        $op = $operation['op'] ?? '';
        if (! in_array($op, self::OPERATIONS, true)) {
            throw new ValidationException('A section change must be one of: '.implode(', ', self::OPERATIONS).'.');
        }
        $layouts = array_column($field['layouts'] ?? [], null, 'key');
        $at = function (?string $id) use ($blocks, $field): int {
            foreach ($blocks as $index => $block) {
                if (($block['_id'] ?? null) === $id) {
                    return $index;
                }
            }
            throw new ValidationException($field['label'].': that section is no longer there. Reload and try again.');
        };
        $id = is_string($operation['id'] ?? null) ? $operation['id'] : null;

        switch ($op) {
            case 'insertBlock':
                $layout = (string) ($operation['layout'] ?? '');
                if (! isset($layouts[$layout])) {
                    throw new ValidationException($field['label'].' has no “'.$layout.'” section.');
                }
                $block = ['_id' => Uuid::v7(), '_layout' => $layout, ...(Json::toArray($operation['set'] ?? []) ?: [])];
                array_splice($blocks, self::position($operation, count($blocks)), 0, [$block]);

                return $blocks;

            case 'moveBlock':
                $from = $at($id);
                $block = $blocks[$from];
                array_splice($blocks, $from, 1);
                array_splice($blocks, min(self::position($operation, count($blocks)), count($blocks)), 0, [$block]);

                return $blocks;

            case 'updateBlock':
                $index = $at($id);
                $set = Json::toArray($operation['set'] ?? []);
                if (! is_array($set) || array_is_list($set)) {
                    throw new ValidationException($field['label'].': a section change must name the values to set.');
                }
                // Only the values named are touched, and a value given as null is cleared: the
                // rest of the block is left exactly as it was.
                $blocks[$index] = array_filter(
                    [...$blocks[$index], ...$set],
                    fn ($one) => $one !== null,
                );

                return $blocks;

            case 'removeBlock':
                array_splice($blocks, $at($id), 1);

                return $blocks;

            default:
                // Duplicated deeply, with its own new id, so the copy is a separate section from
                // the moment it exists.
                $index = $at($id);
                $copy = Json::toArray(Json::decode(Json::encode($blocks[$index])));
                array_splice($blocks, $index + 1, 0, [[...$copy, '_id' => Uuid::v7()]]);

                return $blocks;
        }
    }

    /** Where an operation puts a block: the index it names, or the end. */
    private static function position(array $operation, int $count): int
    {
        $index = $operation['index'] ?? null;

        return is_int($index) || (is_string($index) && ctype_digit($index)) ? max(0, min((int) $index, $count)) : $count;
    }

    /**
     * Refuses a save that would bring in, or change, a section of a kind the field does not
     * offer.
     *
     * Keeping such a section is deliberate: a layout was removed from the definition and the
     * content it described is still the author's (§22). Accepting a *new* one would be
     * something else entirely — a way to put values into an entry that no schema describes — so
     * a block with an unknown layout is allowed through only when it is already stored, exactly
     * as it is stored.
     *
     * @param  list<array<string, mixed>>  $fields  the fields the entry carries
     * @param  array<string, mixed>  $current  the entry's stored values
     * @param  array<string, mixed>  $next  the values being saved
     */
    public static function assertDeprecatedUnchanged(array $fields, array $current, array $next): void
    {
        foreach (self::fieldsIn($fields) as $key => $field) {
            $known = array_column($field['layouts'] ?? [], 'key');
            $stored = [];
            foreach (Json::toArray($current[$key] ?? []) ?: [] as $block) {
                if (is_array($block) && is_string($block['_id'] ?? null)) {
                    $stored[$block['_id']] = $block;
                }
            }
            foreach (Json::toArray($next[$key] ?? []) ?: [] as $block) {
                $layout = is_array($block) ? ($block['_layout'] ?? null) : null;
                if (! is_string($layout) || in_array($layout, $known, true)) {
                    continue;
                }
                $was = $stored[$block['_id'] ?? ''] ?? null;
                if ($was === null || $was != $block) {
                    throw new ValidationException(
                        $field['label'].': “'.$layout.'” is not a kind of section this content offers. A section of a kind that was'
                            .' removed is kept as it is; it cannot be added or edited.',
                        [['path' => 'fields.'.$key, 'fieldKey' => $key, 'code' => 'unknown_layout', 'message' => 'Unknown layout '.$layout]],
                    );
                }
            }
        }
    }

    /**
     * What one block is called in a list of them: its layout's name and the first words it
     * holds — “Quote — Design is…” rather than “Block 4”.
     */
    public static function describe(array $field, array $block): string
    {
        $layouts = array_column($field['layouts'] ?? [], null, 'key');
        $layout = $layouts[$block['_layout'] ?? ''] ?? null;
        $name = $layout['label'] ?? 'Removed section';
        foreach ($layout['fields'] ?? [] as $child) {
            $value = $block[$child['key']] ?? null;
            if (is_string($value) && trim(strip_tags($value)) !== '') {
                $text = trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? '');

                return $name.' — '.(mb_strlen($text) > 60 ? mb_substr($text, 0, 60).'…' : $text);
            }
        }

        return $name;
    }

    /**
     * Blocks in an entry whose layout the field no longer offers, so the editor can say so and
     * the author can decide. Their values are kept until then.
     *
     * @param  list<array<string, mixed>>  $fields  the fields the entry carries
     * @param  array<string, mixed>  $values  the entry's values
     * @return list<array{fieldKey: string, fieldLabel: string, layout: string, count: int}>
     */
    public static function deprecatedIn(array $fields, array $values): array
    {
        $out = [];
        foreach (self::fieldsIn($fields) as $key => $field) {
            $known = array_column($field['layouts'] ?? [], 'key');
            $counts = [];
            foreach (Json::toArray($values[$key] ?? []) ?: [] as $block) {
                $layout = is_array($block) ? ($block['_layout'] ?? null) : null;
                if (is_string($layout) && ! in_array($layout, $known, true)) {
                    $counts[$layout] = ($counts[$layout] ?? 0) + 1;
                }
            }
            foreach ($counts as $layout => $count) {
                $out[] = ['fieldKey' => $key, 'fieldLabel' => $field['label'], 'layout' => (string) $layout, 'count' => $count];
            }
        }

        return $out;
    }

    /** "This entry holds 2 sections of a kind that was removed (quote)." — one line per layout. */
    public static function describeDeprecated(array $deprecated): array
    {
        return array_map(
            fn ($one) => $one['fieldLabel'].' holds '.$one['count'].' '.($one['count'] === 1 ? 'section' : 'sections')
                .' of a kind that was removed (“'.$one['layout'].'”). Their content is kept until you remove or re-add that layout.',
            $deprecated,
        );
    }

    /**
     * How many entries use one layout of one field, for a warning before that layout is removed
     * (“This layout is used in 86 entries”).
     *
     * Answered by the database, over the entries' stored values, so a site with thousands of
     * entries costs one query.
     */
    public function entriesUsingLayout(string $siteId, string $fieldKey, string $layout): int
    {
        if (! preg_match('/'.FieldTypes::pattern('fieldKey').'/D', $fieldKey) || ! preg_match('/'.FieldTypes::pattern('fieldKey').'/D', $layout)) {
            return 0;
        }

        return (int) DB::table('page_drafts as d')->join('pages as p', 'p.id', '=', 'd.page_id')
            ->where('d.site_id', $siteId)->whereNull('p.deleted_at')->whereNull('p.purged_at')
            ->whereRaw('jsonb_path_exists(d.fields, ?::jsonpath)', ['$.'.$fieldKey.'[*] ? (@._layout == "'.$layout.'")'])
            ->count();
    }

    /**
     * The entry's content sections as a reader outside the admin sees them: each block as its
     * layout, its id and its own values, in order.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function forApi(array $fields, array $values): array
    {
        foreach (self::fieldsIn($fields) as $key => $field) {
            if (! isset($values[$key]) || ! is_array($values[$key])) {
                continue;
            }
            $known = array_column($field['layouts'] ?? [], 'key');
            $blocks = [];
            foreach ($values[$key] as $block) {
                if (! is_array($block) || ! in_array($block['_layout'] ?? null, $known, true)) {
                    // A section whose layout is gone is not part of the published shape of this
                    // entry; it stays in storage, not in the API.
                    continue;
                }
                $blocks[] = [
                    'id' => (string) ($block['_id'] ?? ''),
                    'type' => (string) $block['_layout'],
                    'fields' => (object) FieldValues::withoutInternals(array_diff_key($block, ['_id' => true, '_layout' => true])),
                ];
            }
            $values[$key] = $blocks;
        }

        return $values;
    }

    /**
     * The blocks one content-sections field holds, as the renderer reads them: straight from the
     * values the page is being rendered with, so a section costs no query of its own.
     *
     * A block whose layout nothing draws is simply not drawn — an entry keeps what it holds even
     * when a layout has been removed from the definition.
     *
     * @param  array<string, mixed>  $content  the binding context being rendered with
     * @return list<array{layout: string, id: string, values: array<string, mixed>}>
     */
    public static function blocksOf(array $content, string $fieldKey): array
    {
        if ($fieldKey === '') {
            return [];
        }
        $value = Json::toArray(($content['entry'] ?? [])[$fieldKey] ?? []);
        $out = [];
        foreach (is_array($value) ? $value : [] as $block) {
            if (! is_array($block) || ! is_string($block['_layout'] ?? null)) {
                continue;
            }
            $out[] = [
                'layout' => $block['_layout'],
                'id' => (string) ($block['_id'] ?? ''),
                'values' => array_diff_key($block, ['_id' => true, '_layout' => true]),
            ];
        }

        return $out;
    }

    /**
     * The content-sections fields as the Builder needs them: the kinds of section each offers
     * and, for every kind, what a block inside its template can bind to.
     *
     * Offered through the same engine as any other binding source, so a template inside a
     * section layout binds to that section's fields exactly as a page binds to its entry's.
     *
     * @param  list<array<string, mixed>>  $fields  the fields the entry carries
     * @return list<array{key: string, label: string, layouts: list<array{key: string, label: string, bindable: list<array<string, mixed>>}>}>
     */
    public static function forBuilder(array $fields): array
    {
        $out = [];
        foreach (self::fieldsIn($fields) as $key => $field) {
            $out[] = [
                'key' => $key,
                'label' => $field['label'],
                'layouts' => array_values(array_map(fn ($layout) => [
                    'key' => $layout['key'],
                    'label' => $layout['label'],
                    'bindable' => BindingSources::offer($layout['fields']),
                ], $field['layouts'] ?? [])),
            ];
        }

        return $out;
    }

    /** The fields of one layout, for whoever needs to read a block's values (the renderer). */
    public static function layoutFields(array $field, string $layout): array
    {
        return array_column($field['layouts'] ?? [], null, 'key')[$layout]['fields'] ?? [];
    }
}

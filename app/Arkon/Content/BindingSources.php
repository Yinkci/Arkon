<?php

namespace App\Arkon\Content;

/**
 * What a block's property may be bound to, and which properties each thing can feed.
 *
 * The registry is built from the fields that actually apply here — the entry's own and the
 * site's — so the editor never offers a source that does not exist, and the same list answers
 * for the admin, for AI and for anything else that needs to know what can be bound.
 */
final class BindingSources
{
    /**
     * Which kinds of component property a content field can stand in for.
     *
     * A property holds one value of one shape, so the question is only whether the field's own
     * shape fits it. A repeater fits nothing here: it is a list, and a list is a collection to be
     * rendered rather than a value to be placed.
     */
    private const FITS = [
        'text' => ['string'],
        'textarea' => ['string'],
        'rich_text' => ['string'],
        'email' => ['string'],
        'url' => ['string'],
        'phone' => ['string'],
        'select' => ['string'],
        'date' => ['string'],
        'datetime' => ['string'],
        'time' => ['string'],
        'color' => ['string'],
        'number' => ['string'],
        'decimal' => ['string'],
        'boolean' => ['boolean'],
        'media' => ['media'],
        'group' => [],
        // A relationship is a pointer, not a value: what a property can take is a field of what
        // it points at, which is why it is opened up like a group below.
        'relationship' => [],
        'gallery' => [],
        'multi_select' => [],
        'repeater' => [],
        // Content sections are a sequence of blocks: what a property can take is a field of one
        // section, offered inside the template that draws it (Flexible::forBuilder).
        'flexible' => [],
    ];

    /**
     * Everything bindable, as the editor shows it: one list per source, each field with what it
     * can feed. A group is flattened into the fields inside it, because that is what a property
     * can actually take — "Office → City", not "Office".
     *
     * @param  list<array<string, mixed>>  $entryFields
     * @param  list<array<string, mixed>>  $siteFields
     * @return list<array{source: string, label: string, fields: list<array{field: string, path: list<string>, label: string, type: string, fits: list<string>}>}>
     */
    public static function all(array $entryFields, array $siteFields, string $entryLabel = 'This entry'): array
    {
        $sources = [];
        foreach ([['entry', $entryLabel, $entryFields], ['site', 'Site data', $siteFields]] as [$source, $label, $fields]) {
            $offered = self::offer($fields);
            if ($offered !== []) {
                $sources[] = ['source' => $source, 'label' => $label, 'fields' => $offered];
            }
        }

        return $sources;
    }

    /**
     * One source's fields, with groups (and relationships) opened up one level. Public because
     * a content list offers the fields of what it shows, for the item template inside it.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array{field: string, path: list<string>, label: string, type: string, fits: list<string>}>
     */
    public static function offer(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            // A private field is not offered: binding one would put it on a public page, which
            // is the opposite of what marking it private asked for.
            if ($field['settings']['private'] ?? false) {
                continue;
            }
            if ($field['type'] === 'group' || $field['type'] === 'relationship') {
                foreach ($field['fields'] ?? [] as $child) {
                    if ($child['settings']['private'] ?? false) {
                        continue;
                    }
                    $fits = self::FITS[$child['type']] ?? [];
                    if ($fits !== []) {
                        $out[] = [
                            'field' => $field['key'],
                            'path' => [$child['key']],
                            'label' => $field['label'].' → '.$child['label'],
                            'type' => $child['type'],
                            'fits' => $fits,
                        ];
                    }
                }

                continue;
            }
            $fits = self::FITS[$field['type']] ?? [];
            if ($fits !== []) {
                $out[] = ['field' => $field['key'], 'path' => [], 'label' => $field['label'], 'type' => $field['type'], 'fits' => $fits];
            }
        }

        return $out;
    }

    /**
     * What shape a component property wants, from its own schema, so compatibility is decided by
     * what the component declared rather than by a list kept somewhere else.
     *
     * @param  array<string, mixed>  $field  one entry of a component's prop schema
     * @param  list<string>  $mediaRefs  the component's media paths, such as "image.assetId"
     */
    public static function propShape(string $prop, array $field, array $mediaRefs): ?string
    {
        foreach ($mediaRefs as $path) {
            if (str_starts_with((string) $path, $prop.'.')) {
                return 'media';
            }
        }

        return match ($field['type'] ?? null) {
            'string' => 'string',
            'number', 'integer' => 'number',
            'boolean' => 'boolean',
            default => null,
        };
    }
}

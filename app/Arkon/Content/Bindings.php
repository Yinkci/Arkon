<?php

namespace App\Arkon\Content;

use App\Arkon\Support\Json;

/**
 * Where a block's property takes its value from.
 *
 * A node keeps its ordinary props; a binding says that one of them should come from content
 * instead. The prop keeps whatever it held, which is then the fallback — so a document with
 * bindings still renders something when the content is empty or gone, and a binding that cannot
 * be resolved never puts `null` on a page.
 *
 * Resolution happens once per render, against values gathered once, so a page of twenty bound
 * blocks costs no more queries than a page of none.
 */
final class Bindings
{
    /**
     * The values a document's bindings can draw on while it renders.
     *
     * @param  array<string, mixed>  $entry  the entry being rendered, if this is one
     * @param  array<string, mixed>  $site  the site's own values
     */
    public static function context(array $entry = [], array $site = []): array
    {
        return ['entry' => $entry, 'site' => $site];
    }

    /**
     * A node's props with its bindings applied. Props the node has no binding for are untouched,
     * so this is safe to run over every node whether it binds anything or not.
     *
     * Props arrive in raw form, where an empty object is a stdClass — the components' own schema
     * depends on that distinction — so a node that binds nothing, or whose bindings resolve to
     * nothing, is handed back exactly as it came.
     *
     * @param  array<string, mixed>  $context
     */
    public static function apply(mixed $props, mixed $bindings, array $context, array $mediaRefs = [], array $shape = []): mixed
    {
        $resolved = [];
        foreach (self::each($bindings) as $prop => $binding) {
            $value = self::value($binding, $context);
            // Nothing resolved: the prop keeps what it had, which is the fallback a person saw
            // in the editor when they bound it.
            if ($value !== null) {
                $resolved[$prop] = self::forProp($prop, $value, Json::entries($props), $mediaRefs, $shape);
            }
        }

        return $resolved === [] ? $props : [...Json::entries($props), ...$resolved];
    }

    /**
     * A value in the shape the property it feeds expects.
     *
     * A media field holds a reference to the library — `{id}` — while a component names the
     * property it keeps a picture in through `mediaRefs` ("image.assetId"). Rather than making
     * either side know about the other, the reference is renamed to whatever that component
     * calls it, and the alt text the block already had is kept.
     *
     * @param  list<string>  $mediaRefs  paths such as "image.assetId", from the component
     * @param  array<string, mixed>  $shape  the component's own prop schema, for the keys it needs beside the reference
     */
    private static function forProp(string $prop, mixed $value, array $props, array $mediaRefs, array $shape = []): mixed
    {
        // A property that holds text takes text: a price or a date is shown, not arithmetic.
        $wants = $shape[$prop]['type'] ?? null;
        if (($wants === 'string' || $wants === 'link') && (is_int($value) || is_float($value) || is_bool($value))) {
            return is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value;
        }
        if (! is_array($value) || ! isset($value['id']) || count($value) !== 1) {
            return $value;
        }
        foreach ($mediaRefs as $path) {
            [$name, $key] = array_pad(explode('.', (string) $path, 2), 2, null);
            if ($name !== $prop || $key === null) {
                continue;
            }
            $current = is_array($props[$prop] ?? null) ? $props[$prop] : [];
            $out = [...$current, $key => $value['id']];
            // Whatever else this component keeps beside the reference — alt text, most often —
            // is kept from the block when it had it, and otherwise left empty rather than
            // invented: a picture with made-up alt text is worse than one with none.
            foreach ($shape[$prop]['properties'] ?? [] as $sibling => $spec) {
                if (! array_key_exists($sibling, $out)) {
                    $out[$sibling] = ($spec['type'] ?? null) === 'string' ? '' : null;
                }
            }

            return array_filter($out, fn ($v) => $v !== null);
        }

        return $value;
    }

    /**
     * What one binding resolves to, or null when there is nothing there. A media field resolves
     * to the reference an image component expects, so binding a picture is binding a picture and
     * not a URL.
     */
    public static function value(array $binding, array $context): mixed
    {
        $values = $context[$binding['source']] ?? [];
        $value = $values[$binding['field']] ?? null;
        foreach ($binding['path'] ?? [] as $step) {
            $value = is_array($value) ? ($value[$step] ?? null) : null;
        }
        // A list is a repeater, which is a collection rather than one value: a single property
        // cannot stand for it, so it resolves to nothing rather than to something arbitrary.
        if (is_array($value) && array_is_list($value)) {
            return null;
        }

        return $value === '' ? null : $value;
    }

    /**
     * Every binding in a document, as the node it is on and the property it feeds. Used to show
     * what a page depends on and to find bindings that no longer point at anything.
     *
     * @return list<array{nodeId: string, prop: string, source: string, field: string, path: list<string>}>
     */
    public static function inDocument(mixed $document): array
    {
        $out = [];
        foreach (Json::entries(is_array($document) ? ($document['nodes'] ?? []) : []) as $id => $node) {
            foreach (self::each(is_array($node) ? ($node['bindings'] ?? null) : null) as $prop => $binding) {
                $out[] = [
                    'nodeId' => (string) $id,
                    'prop' => (string) $prop,
                    'source' => $binding['source'],
                    'field' => $binding['field'],
                    'path' => $binding['path'] ?? [],
                ];
            }
        }

        return $out;
    }

    /**
     * The media a document's bindings would bring in. The renderer builds its picture of the
     * media library from what the document names, and a bound picture is named by the entry
     * rather than by the block, so these ids have to join that list or the image has nothing to
     * render with.
     *
     * @return list<string>
     */
    public static function mediaIds(mixed $document, array $context): array
    {
        $ids = [];
        foreach (self::inDocument($document) as $binding) {
            $value = self::value($binding, $context);
            if (is_array($value) && isset($value['id']) && is_string($value['id'])) {
                $ids[] = $value['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The bindings in a document that no longer name anything: the field was removed, or the
     * group that carried it no longer applies here. Reported in the editor and before publishing,
     * so a broken binding is found by a person rather than by a visitor.
     *
     * @param  list<array<string, mixed>>  $entryFields  the fields the entry carries
     * @param  list<array<string, mixed>>  $siteFields  the fields site data carries
     * @return list<array{nodeId: string, prop: string, source: string, field: string}>
     */
    public static function broken(mixed $document, array $entryFields, array $siteFields): array
    {
        $known = [
            'entry' => array_column($entryFields, null, 'key'),
            'site' => array_column($siteFields, null, 'key'),
        ];
        $broken = [];
        foreach (self::inDocument($document) as $binding) {
            $field = $known[$binding['source']][$binding['field']] ?? null;
            if ($field === null) {
                $broken[] = ['nodeId' => $binding['nodeId'], 'prop' => $binding['prop'], 'source' => $binding['source'], 'field' => $binding['field']];

                continue;
            }
            // A path only means something inside a group, and only for fields the group has.
            foreach ($binding['path'] as $step) {
                $child = array_column($field['fields'] ?? [], null, 'key')[$step] ?? null;
                if ($child === null) {
                    $broken[] = ['nodeId' => $binding['nodeId'], 'prop' => $binding['prop'], 'source' => $binding['source'], 'field' => $binding['field'].'.'.$step];

                    break;
                }
                $field = $child;
            }
        }

        return $broken;
    }

    /**
     * The bindings of one node, in internal form. Documents arrive raw, where an empty object is
     * a stdClass, so this is the one place that detail is handled.
     *
     * @return array<string, array{source: string, field: string, path?: list<string>}>
     */
    private static function each(mixed $bindings): array
    {
        $bindings = Json::toArray($bindings ?? []);
        if (! is_array($bindings) || array_is_list($bindings)) {
            return [];
        }

        return array_filter($bindings, fn ($binding) => is_array($binding) && isset($binding['source'], $binding['field']));
    }
}

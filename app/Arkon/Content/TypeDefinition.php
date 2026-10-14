<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;
use App\Arkon\Support\Input;
use App\Arkon\Support\Json;

/**
 * What a site's content type says about itself: its names, where its entries live, and the
 * fields its entries carry directly. Validated here so the admin, the API and AI all accept
 * exactly the same definitions; stored in content_types.definition.
 *
 * The fields themselves belong to the shared field engine (FieldSchema), which also validates
 * the fields of a field group — a type's own fields and a group's fields are the same thing.
 *
 * The key is the identity used by routes, templates and the API, so it is checked hard and
 * never changes after creation (ContentTypeService refuses it). Labels are free to change.
 */
final class TypeDefinition
{
    /**
     * Checks a definition and returns it in canonical form (every optional setting present,
     * fields in their stored order).
     *
     * @param  array<string, mixed>  $input
     */
    public static function validate(array $input): array
    {
        // Request bodies arrive in raw form, where an empty JSON object is a stdClass so that a
        // document's `{}` is never mistaken for `[]` (App\Arkon\Support\Json). A definition has
        // no such distinction to keep — `settings: {}` and `choices: []` are an empty map and an
        // empty list — so the whole input becomes internal form once, here, rather than each
        // reader guessing. This is the only place a definition enters the domain.
        $input = Json::toArray($input);
        $limits = FieldTypes::registry()['limits'];
        $d = Input::validate($input, [
            'name' => 'required|string|max:'.$limits['name'],
            'singular' => 'required|string|max:'.$limits['name'],
            'description' => 'present|nullable|string|max:'.$limits['description'],
            'icon' => 'required|string|max:40',
            'pathPrefix' => 'present|nullable|string|max:100',
            'inMenu' => 'required|boolean',
            'menuOrder' => 'required|integer|min:0|max:999',
            'details' => 'required|boolean',
            'taxonomies' => 'present|array|max:10',
            'taxonomies.*' => 'required|string',
            'fields' => 'present|array|max:'.$limits['fields'],
        ]);
        $d['description'] ??= '';
        $d['pathPrefix'] = self::pathPrefix($d['pathPrefix'] ?? '');
        foreach ($d['taxonomies'] as $taxonomy) {
            Taxonomies::get($taxonomy);
        }
        // The type's own fields, checked by the one field engine; `choices` and `default` come
        // with each field, their shape decided by its type.
        $d['fields'] = FieldSchema::fields($input['fields']);

        return $d;
    }

    /** A URL prefix for the type's entries: empty, or /segments with no trailing slash. */
    private static function pathPrefix(?string $prefix): string
    {
        $prefix = trim((string) $prefix);
        if ($prefix === '' || $prefix === '/') {
            return '';
        }
        $prefix = '/'.trim($prefix, '/');
        foreach (explode('/', ltrim($prefix, '/')) as $segment) {
            if (! preg_match('/'.FieldTypes::pattern('slug').'/D', $segment)) {
                throw new ValidationException('The URL prefix may only use lowercase words separated by hyphens.');
            }
        }

        return $prefix;
    }
}

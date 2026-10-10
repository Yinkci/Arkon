<?php

namespace App\Arkon\Api;

use App\Arkon\Content\ContentTypes;
use App\Arkon\Content\Taxonomies;

/**
 * What an API token may be used for. A scope never grants more than the token's user may do:
 * every request is also authorized against the user's current role on the site (Permissions).
 * Content types and taxonomies get their scopes from their registries, so a new type needs no
 * change here.
 */
final class Scopes
{
    /** @return array<string, string> scope => description */
    public static function all(): array
    {
        $scopes = [];
        foreach (ContentTypes::all() as $type) {
            $plural = strtolower($type['plural']);
            $scopes['read:'.$type['collection']] = "Read draft, published and trashed {$plural}";
            $scopes['write:'.$type['collection']] = "Create, change, publish and trash {$plural}";
        }
        foreach (Taxonomies::all() as $taxonomy) {
            $scopes['write:'.$taxonomy['collection']] = 'Create, rename and delete '.strtolower($taxonomy['plural']);
        }

        return [
            ...$scopes,
            'read:media' => 'Read every image in the media library, including unpublished ones',
            'write:media' => 'Upload images, edit their details and move them to the Trash',
            'read:forms' => 'Read form definitions, including unpublished drafts',
            'read:form_entries' => 'Read form entries (personal data)',
            'read:users' => "Read the site's members",
        ];
    }

    /** @param list<string> $scopes */
    public static function valid(array $scopes): bool
    {
        return $scopes !== [] && array_diff($scopes, array_keys(self::all())) === [];
    }
}

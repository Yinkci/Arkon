<?php

namespace App\Http\Api\Resources;

use App\Arkon\Content\Taxonomies;

/** A category, tag or other term. `count` is the number of published items using it. */
final class TermResource
{
    public static function present(array $term): array
    {
        return [
            'id' => $term['id'],
            'taxonomy' => $term['taxonomy'],
            'name' => $term['name'],
            'slug' => $term['slug'],
            'description' => $term['description'],
            ...(Taxonomies::get($term['taxonomy'])['hierarchical'] ? ['parent' => $term['parentId']] : []),
            'count' => $term['count'],
        ];
    }
}

<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;

/**
 * Ways of grouping content (terms). Categories nest one inside another; tags are flat. Like
 * content types, new taxonomies are registered in code and need no schema change.
 */
final class Taxonomies
{
    /** @var array<string, array{label: string, plural: string, collection: string, hierarchical: bool}> */
    private static array $taxonomies = [
        'category' => ['label' => 'Category', 'plural' => 'Categories', 'collection' => 'categories', 'hierarchical' => true],
        'tag' => ['label' => 'Tag', 'plural' => 'Tags', 'collection' => 'tags', 'hierarchical' => false],
    ];

    /** @param array{label: string, plural: string, collection: string, hierarchical: bool} $taxonomy */
    public static function register(string $name, array $taxonomy): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $name)) {
            throw new \InvalidArgumentException("Invalid taxonomy name {$name}");
        }
        self::$taxonomies[$name] = $taxonomy;
    }

    public static function exists(mixed $name): bool
    {
        return is_string($name) && isset(self::$taxonomies[$name]);
    }

    /** @return array{label: string, plural: string, collection: string, hierarchical: bool} */
    public static function get(mixed $name): array
    {
        return self::exists($name) ? self::$taxonomies[$name] : throw new ValidationException('Unknown taxonomy.');
    }

    /** @return array<string, array{label: string, plural: string, collection: string, hierarchical: bool}> */
    public static function all(): array
    {
        return self::$taxonomies;
    }
}

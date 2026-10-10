<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;

/**
 * The kinds of content a site holds. Every kind is stored as a page (pages.kind): the same
 * drafts, revisions, publishing, Trash, redirects, SEO and renderer. A kind adds only what
 * differs: its admin and API names, the URL prefix new items get, whether it has post details
 * (excerpt, featured image) and which taxonomies it uses.
 *
 * New kinds are registered here (or by an extension's service provider through register());
 * the database only bounds the format of the name, so no schema change is needed.
 */
final class ContentTypes
{
    /** @var array<string, array{label: string, plural: string, collection: string, pathPrefix: string, details: bool, taxonomies: list<string>}> */
    private static array $types = [
        'page' => ['label' => 'Page', 'plural' => 'Pages', 'collection' => 'pages', 'pathPrefix' => '', 'details' => false, 'taxonomies' => []],
        'post' => ['label' => 'Post', 'plural' => 'Posts', 'collection' => 'posts', 'pathPrefix' => '/blog', 'details' => true, 'taxonomies' => ['category', 'tag']],
    ];

    /** @param array{label: string, plural: string, collection: string, pathPrefix: string, details: bool, taxonomies: list<string>} $type */
    public static function register(string $kind, array $type): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $kind)) {
            throw new \InvalidArgumentException("Invalid content type name {$kind}");
        }
        foreach ($type['taxonomies'] as $taxonomy) {
            Taxonomies::get($taxonomy);
        }
        self::$types[$kind] = $type;
    }

    public static function exists(mixed $kind): bool
    {
        return is_string($kind) && isset(self::$types[$kind]);
    }

    /** @return array{label: string, plural: string, collection: string, pathPrefix: string, details: bool, taxonomies: list<string>} */
    public static function get(mixed $kind): array
    {
        return self::exists($kind) ? self::$types[$kind] : throw new ValidationException('Unknown content type.');
    }

    /** @return array<string, array{label: string, plural: string, collection: string, pathPrefix: string, details: bool, taxonomies: list<string>}> */
    public static function all(): array
    {
        return self::$types;
    }

    public static function uses(string $kind, string $taxonomy): bool
    {
        return in_array($taxonomy, self::get($kind)['taxonomies'], true);
    }
}

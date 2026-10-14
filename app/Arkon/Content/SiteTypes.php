<?php

namespace App\Arkon\Content;

use App\Arkon\Errors\ValidationException;

/**
 * The content types one site has: the kinds built into Arkon (page, post) together with the
 * types that site has defined. Resolved for exactly one site and never shared between sites,
 * so one site's types can never be read or claimed through another's.
 *
 * Everything that asks "what kinds of content are there?" — the admin, the API, AI, the
 * renderer — asks an instance of this, so they can never disagree.
 */
final class SiteTypes
{
    /**
     * @param  array<string, array{label: string, plural: string, collection: string, pathPrefix: string, details: bool, taxonomies: list<string>, defined?: bool, fields?: list<array<string, mixed>>, schemaVersion?: int, active?: bool, icon?: string, inMenu?: bool, menuOrder?: int, description?: string}>  $types
     */
    public function __construct(
        public readonly string $siteId,
        private readonly array $types,
    ) {}

    public function exists(mixed $kind): bool
    {
        return is_string($kind) && isset($this->types[$kind]);
    }

    /** @return array<string, mixed> */
    public function get(mixed $kind): array
    {
        return $this->exists($kind) ? $this->types[$kind] : throw new ValidationException('Unknown content type.');
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->types;
    }

    /** Only the types this site defined, in menu order: what the admin lists and manages. */
    public function defined(): array
    {
        $defined = array_filter($this->types, fn ($type) => $type['defined'] ?? false);
        uasort($defined, fn ($a, $b) => [$a['menuOrder'], $a['plural']] <=> [$b['menuOrder'], $b['plural']]);

        return $defined;
    }

    public function isDefined(string $kind): bool
    {
        return (bool) ($this->types[$kind]['defined'] ?? false);
    }

    /** A type's field definitions; a built-in kind has none. */
    public function fields(string $kind): array
    {
        return $this->get($kind)['fields'] ?? [];
    }

    /** The definition entry values are checked against, or null for a built-in kind. */
    public function definition(string $kind): ?array
    {
        $type = $this->get($kind);

        return ($type['defined'] ?? false) ? ['fields' => $type['fields']] : null;
    }

    public function uses(string $kind, string $taxonomy): bool
    {
        return in_array($taxonomy, $this->get($kind)['taxonomies'], true);
    }
}

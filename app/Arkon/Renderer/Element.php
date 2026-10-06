<?php

namespace App\Arkon\Renderer;

use InvalidArgumentException;

/**
 * Arkon's intermediate representation: what components return instead of
 * strings. The serializer escapes everything, so component code cannot produce
 * unescaped markup.
 */
final class Element
{
    private const TAG = '/^[a-z][a-z0-9]*$/D';

    // Components describe content and layout. Executable or style-injecting elements
    // are produced only by the renderer itself (head), never by components.
    private const FORBIDDEN_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'base', 'meta', 'link'];

    /**
     * @param  array<string, string|int|bool|null>  $attrs
     * @param  list<Element|TextNode>  $children
     */
    private function __construct(public readonly string $tag, public array $attrs, public readonly array $children) {}

    /**
     * Builds an element. Children may be Elements, Texts, strings, null/false
     * (skipped) or nested lists.
     */
    public static function h(string $tag, ?array $attrs = null, mixed ...$children): self
    {
        if (preg_match(self::TAG, $tag) !== 1 || in_array($tag, self::FORBIDDEN_TAGS, true)) {
            throw new InvalidArgumentException("Element <{$tag}> is not allowed");
        }

        return new self($tag, $attrs ?? [], self::flatten($children));
    }

    /** @return list<Element|TextNode> */
    private static function flatten(array $children): array
    {
        $out = [];
        foreach ($children as $child) {
            if ($child === null || $child === false) {
                continue;
            }
            if (is_string($child)) {
                $out[] = new TextNode($child);
            } elseif (is_array($child)) {
                array_push($out, ...self::flatten($child));
            } elseif ($child instanceof self || $child instanceof TextNode) {
                $out[] = $child;
            } else {
                throw new InvalidArgumentException('Invalid child');
            }
        }

        return $out;
    }
}

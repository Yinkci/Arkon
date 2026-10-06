<?php

namespace App\Arkon\Renderer;

use App\Arkon\Support\Text;
use InvalidArgumentException;

/** IR → HTML. Escaping by default; event handlers and unsafe URLs are refused. */
final class Serializer
{
    private const VOID = ['area', 'br', 'col', 'hr', 'img', 'input', 'source', 'track', 'wbr'];

    private const ATTR_NAME = '/^[a-z][a-z0-9-]*$/D';

    private const URL_ATTRS = ['href', 'src', 'action', 'formaction', 'poster'];

    public static function escapeText(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    public static function escapeAttr(string $value): string
    {
        return str_replace('"', '&quot;', self::escapeText($value));
    }

    /** Allows relative URLs and http(s)/mailto/tel. Blocks javascript:, data: and friends. */
    public static function isSafeUrl(string $value): bool
    {
        $trimmed = Text::trim($value);
        if (preg_match('#^(/(?!/)|\#|\?)#', $trimmed) === 1) {
            return true;
        }

        return preg_match('/^(https?:|mailto:|tel:)/i', $trimmed) === 1;
    }

    public static function serialize(Element|TextNode $node): string
    {
        if ($node instanceof TextNode) {
            return self::escapeText($node->value);
        }
        $open = '<'.$node->tag.self::attributes($node->attrs).'>';
        if (in_array($node->tag, self::VOID, true)) {
            return $open;
        }
        $inner = '';
        foreach ($node->children as $child) {
            $inner .= self::serialize($child);
        }

        return $open.$inner.'</'.$node->tag.'>';
    }

    public static function countElements(Element|TextNode $node): int
    {
        if ($node instanceof TextNode) {
            return 0;
        }
        $count = 1;
        foreach ($node->children as $child) {
            $count += self::countElements($child);
        }

        return $count;
    }

    private static function attributes(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $name = (string) $name;
            // Event handlers are never valid output; attributes are data, not code.
            if (preg_match(self::ATTR_NAME, $name) !== 1 || str_starts_with($name, 'on')) {
                throw new InvalidArgumentException("Attribute \"{$name}\" is not allowed");
            }
            if ($value === true) {
                $out .= ' '.$name;

                continue;
            }
            $string = (string) $value;
            if (in_array($name, self::URL_ATTRS, true) && ! self::isSafeUrl($string)) {
                throw new InvalidArgumentException("Unsafe URL in {$name}");
            }
            $out .= ' '.$name.'="'.self::escapeAttr($string).'"';
        }

        return $out;
    }
}

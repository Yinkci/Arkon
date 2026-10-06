<?php

namespace App\Arkon\Support;

/**
 * String semantics shared with the TypeScript editor. Lengths are counted in
 * UTF-16 code units (JavaScript's `string.length`, and what an input's
 * maxlength counts), and trimming removes exactly what JavaScript's trim() does.
 */
final class Text
{
    /** JavaScript's whitespace set (String.prototype.trim / \s). */
    private const WHITESPACE = '[\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';

    public static function utf16Length(string $value): int
    {
        return intdiv(strlen((string) mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')), 2);
    }

    public static function trim(string $value): string
    {
        return (string) preg_replace('/^'.self::WHITESPACE.'+|'.self::WHITESPACE.'+$/u', '', $value);
    }

    public static function isBlank(string $value): bool
    {
        return self::trim($value) === '';
    }

    /** Replaces {name} placeholders. */
    public static function format(string $template, array $values = []): string
    {
        foreach ($values as $key => $value) {
            $template = str_replace('{'.$key.'}', (string) $value, $template);
        }

        return $template;
    }
}

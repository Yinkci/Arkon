<?php

namespace App\Arkon\Forms;

final class EntryIndex
{
    public static function terms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}@._+\-]+/u', mb_strtolower($text), $m);

        return array_values(array_unique(array_filter($m[0], fn ($v) => mb_strlen($v) <= 254)));
    }

    public static function tokens(array $values): array
    {
        $text = implode(' ', array_map(fn ($v) => is_array($v) ? implode(' ', $v) : (string) $v, $values));

        return array_map(self::hash(...), self::terms($text));
    }

    public static function hash(string $term): string
    {
        return hash_hmac('sha256', $term, config('app.key').'arkon-form-search');
    }
}

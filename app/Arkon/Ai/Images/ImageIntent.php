<?php

namespace App\Arkon\Ai\Images;

/**
 * Whether a brief explicitly asks for a newly generated image (resources/arkon/ai-images.json,
 * shared with the TypeScript twin). It is used only to ask the user before a run whether to
 * generate or to continue without generated images; it never chooses for them.
 */
final class ImageIntent
{
    private static ?array $rules = null;

    public static function rules(): array
    {
        return self::$rules ??= json_decode(file_get_contents(resource_path('arkon/ai-images.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function requested(string $text): bool
    {
        $rules = self::rules();
        foreach ($rules['negations'] as $pattern) {
            if (preg_match('/'.$pattern.'/iu', $text) === 1) {
                return false;
            }
        }
        foreach ($rules['patterns'] as $pattern) {
            if (preg_match('/'.$pattern.'/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    public static function maxPerRun(): int
    {
        return (int) self::rules()['maxImagesPerRun'];
    }
}

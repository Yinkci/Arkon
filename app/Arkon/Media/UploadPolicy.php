<?php

namespace App\Arkon\Media;

/** Shared byte policy; PHP limits describe the serving process, never the browser's guess. */
final class UploadPolicy
{
    private static ?array $rules = null;

    /** resources/arkon/media.json, read once per process. */
    public static function rules(): array
    {
        return self::$rules ??= json_decode(file_get_contents(resource_path('arkon/media.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') {
            return PHP_INT_MAX;
        }
        $factor = match (strtolower(substr($value, -1))) {
            'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1
        };

        return (int) ((float) $value * $factor);
    }

    public static function forRuntime(?string $upload = null, ?string $post = null): array
    {
        $rules = self::rules();
        $effective = min($rules['maxImageUploadBytes'], self::iniBytes($upload ?? ini_get('upload_max_filesize')),
            max(0, self::iniBytes($post ?? ini_get('post_max_size')) - $rules['requestHeadroomBytes']));

        return [...$rules, 'effectiveMaxBytes' => $effective, 'runtimeLimited' => $effective < $rules['maxImageUploadBytes']];
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 ** 2) {
            return rtrim(rtrim(number_format($bytes / 1024, 1, '.', ''), '0'), '.').' KiB';
        }

        return rtrim(rtrim(number_format($bytes / (1024 ** 2), 1, '.', ''), '0'), '.').' MiB';
    }

    public static function sizeError(int $bytes): string
    {
        return 'This image is '.self::formatBytes($bytes).' ('.$bytes.' bytes). The maximum image size is '.self::formatBytes(self::rules()['maxImageUploadBytes']).' ('.self::rules()['maxImageUploadBytes'].' bytes).';
    }
}

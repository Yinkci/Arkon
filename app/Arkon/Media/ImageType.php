<?php

namespace App\Arkon\Media;

/** Identifies an image from its magic bytes. The file name and client-sent MIME type are never trusted. */
final class ImageType
{
    /** @return array{mime: string, ext: string}|null */
    public static function detect(string $bytes): ?array
    {
        if (strlen($bytes) < 12) {
            return null;
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return ['mime' => 'image/jpeg', 'ext' => 'jpg'];
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return ['mime' => 'image/png', 'ext' => 'png'];
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return ['mime' => 'image/gif', 'ext' => 'gif'];
        }
        if (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return ['mime' => 'image/webp', 'ext' => 'webp'];
        }
        if (substr($bytes, 4, 4) === 'ftyp' && in_array(substr($bytes, 8, 4), ['avif', 'avis'], true)) {
            return ['mime' => 'image/avif', 'ext' => 'avif'];
        }

        return null;
    }
}

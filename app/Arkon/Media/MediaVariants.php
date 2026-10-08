<?php

namespace App\Arkon\Media;

use GdImage;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Resized WebP copies of uploaded images, for responsive `srcset`s. The original is
 * always kept unchanged. A variant is delivered under exactly the same policy as its
 * original (MediaService::resolveAccess resolves it to the original asset), so a
 * private image stays private in every size.
 *
 * Variants are made at standard widths below the original's width (and at the
 * original's width when that is small enough), only when the result is smaller than
 * the original. Animated formats (GIF) and very large images are left alone.
 */
class MediaVariants
{
    /** @var list<int> */
    public const WIDTHS = [320, 640, 960, 1280, 1600, 1920];

    public const QUALITY = 78;

    /** Decoding needs about 4 bytes per pixel; larger images keep only their original. */
    private const MAX_PIXELS = 40_000_000;

    public function __construct(private readonly MediaStorage $storage) {}

    public static function key(string $assetId, int $width): string
    {
        return "{$assetId}-w{$width}.webp";
    }

    /** Whether this PHP can make variants at all. */
    public static function supported(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromstring');
    }

    /**
     * Creates the missing variants of one asset. Idempotent: existing rows are kept.
     *
     * @return int variants created
     */
    public function generate(object $asset): int
    {
        if (! self::supported() || $asset->mime === 'image/gif' || (int) $asset->width * (int) $asset->height > self::MAX_PIXELS) {
            return 0;
        }
        $existing = DB::table('media_variants')->where('asset_id', $asset->id)->pluck('width')->map(fn ($w) => (int) $w)->all();
        $widths = array_values(array_filter(self::WIDTHS, fn ($w) => $w < (int) $asset->width));
        if ((int) $asset->width <= self::WIDTHS[array_key_last(self::WIDTHS)] && $asset->mime !== 'image/webp') {
            $widths[] = (int) $asset->width; // the full size, re-encoded as WebP (usually much smaller than PNG or JPEG)
        }
        $widths = array_values(array_diff(array_unique($widths), $existing));
        if ($widths === []) {
            return 0;
        }
        $data = $this->storage->read($asset->storage_key);
        $source = $data === null ? false : @imagecreatefromstring($data);
        if (! $source instanceof GdImage) {
            return 0;
        }
        $created = 0;
        try {
            foreach ($widths as $width) {
                $height = max(1, (int) round((int) $asset->height * $width / (int) $asset->width));
                $bytes = $this->encode($source, $width, $height);
                // A variant that is not smaller than the original is no use.
                if ($bytes === null || strlen($bytes) >= (int) $asset->bytes) {
                    continue;
                }
                $key = self::key($asset->id, $width);
                try {
                    $this->storage->put($key, $bytes);
                } catch (Throwable) {
                    continue; // a concurrent run wrote it first
                }
                $created += DB::table('media_variants')->insertOrIgnore([
                    'asset_id' => $asset->id, 'site_id' => $asset->site_id, 'format' => 'webp',
                    'width' => $width, 'height' => $height, 'bytes' => strlen($bytes), 'storage_key' => $key,
                ]);
            }
        } finally {
            imagedestroy($source);
        }

        return $created;
    }

    private function encode(GdImage $source, int $width, int $height): ?string
    {
        $target = imagecreatetruecolor($width, $height);
        if ($target === false) {
            return null;
        }
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        ob_start();
        $ok = imagewebp($target, null, self::QUALITY);
        $bytes = (string) ob_get_clean();
        imagedestroy($target);

        return $ok && $bytes !== '' ? $bytes : null;
    }
}

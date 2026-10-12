<?php

namespace App\Arkon\Ai\Images;

use App\Arkon\Ai\AiException;
use App\Arkon\Errors\ValidationException;
use App\Arkon\Media\ImageType;
use App\Arkon\Media\MediaService;
use App\Arkon\Media\UploadPolicy;
use App\Arkon\Sites\SiteContext;

/**
 * Turns a provider's image into a managed media asset: checks the real bytes (type, dimensions,
 * size), re-encodes photographs as WebP when they would be served as large PNGs, then stores it
 * through MediaService (private storage, the upload limits, responsive WebP variants) under the
 * asset id reserved for its placement, with provenance. Nothing is embedded in pages: the proposal
 * refers to the asset id, and the asset is public only once a live publication uses it.
 */
final class GeneratedImageImporter
{
    /** Provider output larger than this is refused before it is decoded. */
    public const MAX_SOURCE_BYTES = 25 * 1024 * 1024;

    public function __construct(private readonly MediaService $media) {}

    public function import(SiteContext $ctx, string $assetId, GeneratedImage $image, array $spec, array $provenance): array
    {
        $bytes = strlen($image->bytes);
        if ($bytes === 0 || $bytes > self::MAX_SOURCE_BYTES) {
            throw new AiException('IMAGE_INVALID', 'The generated image file is empty or too large to import ('.UploadPolicy::formatBytes($bytes).').');
        }
        $type = ImageType::detect($image->bytes);
        if ($type === null || ! in_array($type['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new AiException('IMAGE_INVALID', 'The provider did not return a PNG, JPEG or WebP image.');
        }
        $size = @getimagesizefromstring($image->bytes);
        if (! is_array($size) || $size[0] < 64 || $size[1] < 64) {
            throw new AiException('IMAGE_INVALID', 'The generated image is corrupt or too small.');
        }
        $data = $this->optimize($image->bytes, $type['mime']);
        $ext = ImageType::detect($data)['ext'];
        try {
            return $this->media->importGenerated($ctx, $assetId, $data, 'generated-'.substr($assetId, -12).'.'.$ext, [
                'title' => $spec['title'] ?: 'Generated image',
                'alt' => $spec['alt'],
                'description' => 'AI-generated ('.$image->provider.'): '.$spec['subject'],
                'provenance' => [
                    ...$provenance,
                    'provider' => $image->provider,
                    'request' => array_intersect_key($spec, array_flip(['subject', 'visualDirection', 'placement', 'aspectRatio', 'keepVisible'])),
                    'revisedPrompt' => $image->revisedPrompt !== null ? mb_substr($image->revisedPrompt, 0, 2000) : null,
                    'sourceMime' => $type['mime'],
                    'sourceBytes' => $bytes,
                    'sourceWidth' => (int) $size[0],
                    'sourceHeight' => (int) $size[1],
                ],
            ]);
        } catch (ValidationException $e) {
            throw new AiException('IMAGE_INVALID', 'The generated image was rejected by the media library: '.$e->getMessage());
        }
    }

    /** A lossless PNG photograph is re-encoded as high-quality WebP (often 5–10× smaller); anything else is kept. */
    private function optimize(string $data, string $mime): string
    {
        if ($mime !== 'image/png' || ! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            return $data;
        }
        $image = @imagecreatefromstring($data);
        if ($image === false) {
            return $data;
        }
        try {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
            ob_start();
            $ok = imagewebp($image, null, 86);
            $webp = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        return $ok && $webp !== '' && strlen($webp) < strlen($data) ? $webp : $data;
    }
}

<?php

namespace App\Arkon\Renderer;

/** A bounded responsive background candidate. URLs remain managed media URLs, including signed previews. */
final class BackgroundImages
{
    public static function url(array $media, string $screen = 'base'): string
    {
        $target = min((int) $media['width'], ['base' => 1600, 'tablet' => 1280, 'mobile' => 960][$screen] ?? 1600);
        $variants = $media['variants'] ?? [];
        usort($variants, fn ($a, $b) => $a['width'] <=> $b['width']);
        foreach ($variants as $variant) {
            if ($variant['width'] >= $target) {
                return $variant['url'];
            }
        }

        return $media['url'];
    }
}

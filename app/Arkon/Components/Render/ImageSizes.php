<?php

namespace App\Arkon\Components\Render;

/** Conservative image slot estimates; never depend on browser JavaScript or rewrite old publications. */
final class ImageSizes
{
    public static function childShares(array $parent, array $inherited, int $count, int $position): array
    {
        $effective = null;
        $result = $inherited;
        foreach (['base', 'tablet', 'mobile'] as $bp) {
            $effective = $parent['style']['root'][$bp]['columns'] ?? $effective;
            $layout = $effective ?? (string) max(1, $count);
            $share = 1.0;
            if (preg_match('/^[1-6]$/D', (string) $layout)) {
                $share = 1 / (int) $layout;
            } elseif (preg_match('/^\d+(?:\.\d+)?fr(?: \d+(?:\.\d+)?fr)*$/D', (string) $layout)) {
                $weights = array_map(fn ($v) => (float) $v, explode(' ', trim($layout)));
                if (array_sum($weights) > 0) {
                    $share = $weights[$position % count($weights)] / array_sum($weights);
                }
            }
            $result[$bp] = ($inherited[$bp] ?? 1) * $share;
        }

        return $result;
    }

    public static function attribute(array $shares, array $style): string
    {
        $width = null;
        $sizes = [];
        foreach (['base', 'tablet', 'mobile'] as $bp) {
            $width = $style['media'][$bp]['width'] ?? $width;
            $share = max(0.01, min(1.0, $shares[$bp] ?? 1));
            $vw = round(100 * $share, 2).'vw';
            $slot = $vw;
            // Fixed image widths and percentages reduce the download estimate; unknown/auto units use the safe slot estimate.
            if (is_string($width) && preg_match('/^(\d+(?:\.\d+)?)(px|rem|vw|%)$/D', $width, $m)) {
                $cap = $m[2] === '%' ? round(100 * $share * min(1, (float) $m[1] / 100), 2).'vw' : $width;
                $slot = 'min('.$slot.', '.$cap.')';
            }
            $sizes[$bp] = $slot;
        }

        return '(max-width: 599px) '.$sizes['mobile'].', (max-width: 899px) '.$sizes['tablet'].', '.$sizes['base'];
    }
}

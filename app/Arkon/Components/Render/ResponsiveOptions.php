<?php

namespace App\Arkon\Components\Render;

/** Validated visual presets inherit base -> tablet -> mobile and render through CSS. */
final class ResponsiveOptions
{
    public static function attributes(array $props, array $keys): array
    {
        $attributes = [];
        $effective = $props;
        foreach (['tablet', 'mobile'] as $screen) {
            foreach ($keys as $key) {
                $value = $props['responsive'][$screen][$key] ?? 'inherit';
                if ($value !== 'inherit') {
                    $effective[$key] = $value;
                }
                // No override: omit the attribute, preserving the component's exact base preset.
                if ($value !== 'inherit' || ($props['responsive']['tablet'][$key] ?? 'inherit') !== 'inherit' && $screen === 'mobile') {
                    $attributes['data-'.$screen.'-'.strtolower($key)] = $effective[$key];
                }
            }
        }

        return $attributes;
    }
}

<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

final class IconV1 implements ComponentRenderer
{
    public const PATHS = [
        'arrow-up' => 'M12 20V4m-7 7 7-7 7 7', 'arrow-right' => 'M4 12h16m-7-7 7 7-7 7',
        'mail' => 'M3 5h18v14H3z m0 1 9 7 9-7', 'phone' => 'M5 3h4l2 5-3 2c2 3 3 4 6 6l2-3 5 2v4c-8 4-20-8-16-16z',
        'pin' => 'M12 22s8-8 8-14a8 8 0 0 0-16 0c0 6 8 14 8 14z M9 8a3 3 0 1 0 6 0a3 3 0 1 0-6 0',
        'screen' => 'M3 3h18v13H3z M8 21h8 M12 16v5 M7 7h10 M7 11h6',
        'share' => 'M6 12 18 5 M6 12l12 7 M3 12a3 3 0 1 0 6 0a3 3 0 1 0-6 0 M15 4a3 3 0 1 0 6 0a3 3 0 1 0-6 0 M15 20a3 3 0 1 0 6 0a3 3 0 1 0-6 0',
        'chart' => 'M3 3v18h18 M6 16l5-5 4 2 6-8 M16 5h5v5',
        'gift' => 'M3 8h18v4H3z M5 12v9h14v-9 M12 8v13 M12 8S3 8 5 3c2-3 7 5 7 5s9 0 7-5c-2-3-7 5-7 5',
        'leaf' => 'M4 20 19 5 M4 16C1 4 13 2 22 2c0 12-4 21-16 17',
        'check' => 'M4 12l5 5L20 6', 'facebook' => 'M14 22V12h4l1-5h-5V5c0-2 2-2 5-2V0h-5c-4 0-5 3-5 6v1H6v5h3v10',
        'instagram' => 'M6 2h12a4 4 0 0 1 4 4v12a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V6a4 4 0 0 1 4-4 M8 12a4 4 0 1 0 8 0a4 4 0 1 0-8 0 M18 6h.01',
        'linkedin' => 'M3 8v13 M3 3v1 M9 21V8 M9 13c0-7 11-7 11 0v8',
        'youtube' => 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2 M10 8l6 4-6 4z',
    ];

    public static function svg(string $name): Element
    {
        return Element::h('svg', ['viewbox' => '0 0 24 24', 'width' => 24, 'height' => 24, 'fill' => 'none', 'stroke' => 'currentColor', 'stroke-width' => '1.7', 'stroke-linecap' => 'round', 'stroke-linejoin' => 'round', 'aria-hidden' => 'true', 'focusable' => 'false'], Element::h('path', ['d' => self::PATHS[$name] ?? self::PATHS['screen']]));
    }

    public function render(RenderContext $ctx): Element
    {
        $label = $ctx->props['label'];
        $attrs = ['class' => $ctx->classes('root', 'ak-icon'), 'aria-label' => $label !== '' ? $label : null, 'aria-hidden' => $label === '' ? 'true' : null];

        return $ctx->props['linked'] ? Element::h('a', [...$attrs, 'href' => $ctx->props['href']], self::svg($ctx->props['name'])) : Element::h('span', [...$attrs, 'role' => $label !== '' ? 'img' : null], self::svg($ctx->props['name']));
    }
}

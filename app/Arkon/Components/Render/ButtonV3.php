<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/**
 * A call-to-action link. `href` is validated against the shared safe-link
 * pattern, and the serializer refuses unsafe URLs again. Links opening a new tab
 * get rel="noopener noreferrer".
 */
final class ButtonV3 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $props = $ctx->props;
        $classes = ['ak-btn3', 'ak-btn3--responsive', "ak-btn3--{$props['variant']}", $props['size'] === 'medium' ? null : "ak-btn3--{$props['size']}"];

        return Element::h(
            'p',
            [...$ctx->part('root'), 'class' => $ctx->classes('root', 'ak-action2', 'ak-flow')],
            Element::h('a', [
                ...$ctx->part('button'),
                'class' => $ctx->classes('button', ...$classes),
                ...ResponsiveOptions::attributes($props, ['variant', 'size']),
                'href' => $props['href'] === '' ? '#' : $props['href'],
                'target' => $props['newTab'] ? '_blank' : null,
                'rel' => $props['newTab'] ? 'noopener noreferrer' : null,
                ...$ctx->editable('label', 'Button label'),
            ], $props['label']),
        );
    }
}

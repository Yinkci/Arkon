<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/**
 * A call-to-action link. `href` is validated against the shared safe-link
 * pattern, and the serializer refuses unsafe URLs again as a second line of
 * defence. Links opening a new tab get rel="noopener noreferrer".
 */
final class ButtonV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $props = $ctx->props;

        return Element::h(
            'p',
            ['class' => 'ak-action'],
            Element::h('a', [
                'class' => "ak-button ak-button--{$props['style']}",
                'href' => $props['href'] === '' ? '#' : $props['href'],
                'target' => $props['newTab'] ? '_blank' : null,
                'rel' => $props['newTab'] ? 'noopener noreferrer' : null,
                ...$ctx->editable('label', 'Button label'),
            ], $props['label']),
        );
    }
}

<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/**
 * A full-width band (backgrounds reach the edges) whose content stays within a
 * container width, without an extra inner wrapper: the inline padding is
 * computed from the content width.
 */
final class SectionV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $width = $ctx->props['contentWidth'];

        return Element::h(
            $ctx->props['element'],
            ['class' => $ctx->classes('root', 'ak-section', $width === 'default' ? null : "ak-section--{$width}")],
            $ctx->children,
        );
    }
}

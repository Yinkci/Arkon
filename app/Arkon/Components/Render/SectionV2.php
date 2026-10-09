<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

final class SectionV2 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $width = $ctx->props['contentWidth'];

        return Element::h($ctx->props['element'], ['id' => ($ctx->props['anchor'] ?? '') ?: null, 'class' => $ctx->classes('root', 'ak-section', $width === 'default' ? null : 'ak-section--'.$width)], $ctx->children);
    }
}

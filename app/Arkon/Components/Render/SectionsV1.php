<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/**
 * The content sections of the entry being rendered: each block drawn by the template registered
 * for its layout.
 *
 * The blocks themselves are the entry's own values, resolved before rendering, and each one is
 * rendered through the matching `section-template` child — one canonical mapping from a layout
 * to its template. Nothing about how a section looks lives in the stored content: that is the
 * template's business, which is what keeps structured content and visual design apart.
 */
final class SectionsV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h('div', [
            'class' => $ctx->classes('root', 'ak-sections'),
            'data-ak-sections' => $ctx->mode === 'editor' ? ($ctx->props['field'] ?: 'none') : null,
        ], $ctx->items);
    }
}

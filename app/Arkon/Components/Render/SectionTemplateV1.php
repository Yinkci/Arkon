<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/**
 * How one kind of content section is drawn. Rendered once for every block of its layout, with
 * that block's values in place of the entry's, so the blocks inside it bind to "this section".
 */
final class SectionTemplateV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h('div', [
            'class' => $ctx->classes('root', 'ak-section-template'),
            'data-ak-layout' => $ctx->mode === 'editor' ? ($ctx->props['layout'] ?: 'none') : null,
        ], $ctx->children);
    }
}

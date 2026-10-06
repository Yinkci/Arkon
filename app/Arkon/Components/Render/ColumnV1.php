<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** One column of a Columns block: a vertical stack of text, images and buttons. */
final class ColumnV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h('div', ['class' => 'ak-column'], $ctx->children);
    }
}

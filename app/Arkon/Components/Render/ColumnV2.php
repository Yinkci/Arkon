<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** One column of a Columns block: a vertical stack by default. */
final class ColumnV2 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h('div', ['class' => $ctx->classes('root', 'ak-col')], $ctx->children);
    }
}

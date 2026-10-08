<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** The page: top-level blocks with the class `ak-flow` sit in the centred content column. */
final class PageV3 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h('main', ['class' => $ctx->classes('root', 'ak-main')], $ctx->children);
    }
}

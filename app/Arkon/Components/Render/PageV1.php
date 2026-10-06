<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

final class PageV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h('main', null, $ctx->children);
    }
}

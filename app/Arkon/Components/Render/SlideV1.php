<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

final class SlideV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h('div', ['class' => $ctx->classes('root', 'ak-slide'), 'role' => 'group', 'aria-roledescription' => 'slide'], $ctx->children);
    }
}

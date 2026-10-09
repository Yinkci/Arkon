<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

final class BackTopV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h('a', ['class' => $ctx->classes('root', 'ak-backtop'), 'href' => '#', 'aria-label' => $ctx->props['label'], 'data-arkon-top' => $ctx->mode === 'production' ? '' : null], IconV1::svg('arrow-up'));
    }
}

<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** The root of a reusable component's document; rendered as a page when the component is previewed on its own. */
final class FragmentV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h('main', ['class' => 'ak-main'], $ctx->children);
    }
}

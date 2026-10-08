<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/**
 * A linked use of a reusable component. Its children are the blocks of the
 * component's published version, rendered by PageRenderer (never a draft).
 */
final class InstanceV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h('div', ['class' => $ctx->classes('root', 'ak-instance')], $ctx->children);
    }
}

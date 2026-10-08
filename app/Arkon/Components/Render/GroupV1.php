<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** A flex (default: vertical stack) or grid container for nested layouts. */
final class GroupV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        return Element::h($ctx->props['element'], ['class' => $ctx->classes('root', 'ak-group', 'ak-flow')], $ctx->children);
    }
}

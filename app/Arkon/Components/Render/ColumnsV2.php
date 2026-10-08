<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/**
 * A grid of columns. Equal widths by default (a class per count); proportions,
 * gaps and per-breakpoint stacking come from the style prop.
 */
final class ColumnsV2 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $count = count($ctx->children);

        return Element::h('div', ['class' => $ctx->classes('root', 'ak-cols', 'ak-flow', $count > 1 ? "ak-cols--n{$count}" : null)], $ctx->children);
    }
}

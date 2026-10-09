<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** A flex (default: vertical stack) or grid container for nested layouts. */
final class GroupV2 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $sticky = [];
        foreach ($ctx->props['style']['root'] ?? [] as $bp => $values) {
            $subset = array_intersect_key($values, array_flip(['position', 'top', 'zIndex']));
            if ($subset !== []) {
                $sticky[$bp] = $subset;
            }
        }
        $stickyClass = $ctx->props['element'] === 'header' && $sticky !== [] ? $ctx->styles?->classFor($sticky) : null;

        return Element::h($ctx->props['element'], ['class' => $ctx->classes('root', 'ak-group', 'ak-flow'), 'data-ak-sticky-class' => $stickyClass], $ctx->children);
    }
}

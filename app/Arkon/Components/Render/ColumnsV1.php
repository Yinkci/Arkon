<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/**
 * Side-by-side columns that stack below a breakpoint. The column count is a
 * class (never inline styles), so production CSS stays static and cacheable.
 */
final class ColumnsV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $props = $ctx->props;
        $count = count($ctx->children);
        $classes = ['ak-columns', "ak-columns--stack-{$props['stackOn']}"];
        if ($count > 1) {
            $classes[] = "ak-columns--n{$count}";
        }
        if ($props['gap'] !== 'medium') {
            $classes[] = "ak-columns--gap-{$props['gap']}";
        }

        return Element::h('div', ['class' => implode(' ', $classes)], $ctx->children);
    }
}

<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** Keep shared site landmarks outside the main content landmark. Plain pages retain their existing markup. */
final class PageV5 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $before = [];
        $main = [];
        $after = [];
        foreach ($ctx->children as $child) {
            $landmark = $child instanceof Element ? $child->tag : null;
            if ($child instanceof Element && str_contains($child->attrs['class'] ?? '', 'ak-instance') && count($child->children) === 1 && $child->children[0]instanceof Element) {
                $inner = $child->children[0];
                $landmark = $inner->tag;
                if (isset($inner->attrs['data-ak-sticky-class'])) {
                    $child->attrs['class'] .= ' '.$inner->attrs['data-ak-sticky-class'];
                    unset($inner->attrs['data-ak-sticky-class']);
                }
            }
            if ($child instanceof Element) {
                unset($child->attrs['data-ak-sticky-class']);
            }
            if ($landmark === 'header') {
                $before[] = $child;
            } elseif ($landmark === 'footer') {
                $after[] = $child;
            } else {
                $main[] = $child;
            }
        }
        if ($before === [] && $after === []) {
            return Element::h('main', ['class' => $ctx->classes('root', 'ak-main')], $main);
        }

        return Element::h('div', ['class' => $ctx->classes('root', 'ak-site')], $before, Element::h('main', ['class' => 'ak-main'], $main), $after);
    }
}

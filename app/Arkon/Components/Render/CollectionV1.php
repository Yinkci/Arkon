<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/**
 * A content list: one item template, rendered once for every entry the query matched.
 *
 * The entries themselves are resolved before rendering (App\Arkon\Content\Collections) and
 * arrive as `$ctx->items`, one list of already-rendered children per entry — so this renderer
 * only decides the markup around them, and nothing here knows how to query anything.
 *
 * Layout belongs to the styling model, not to this component: the root slot is an ordinary
 * container whose display, columns and gap come from the style props, so a list and a grid are
 * the same component styled differently.
 */
final class CollectionV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $children = array_map(
            fn (array $item, int $index) => Element::h('div', [
                'class' => $ctx->classes('item', 'ak-collection__item'),
                ...($ctx->mode === 'editor' && $index === 0 ? ['data-ak-template' => 'item'] : []),
            ], $item),
            $ctx->items,
            array_keys($ctx->items),
        );
        // Nothing matched: what the author wrote for that case, so a page is never silently
        // blank. In the editor the sample item takes its place, which is why this is only
        // reached when there is genuinely nothing to show.
        if ($children === []) {
            $children = $ctx->props['emptyText'] === ''
                ? []
                : [Element::h('p', ['class' => 'ak-collection__empty', ...$ctx->editable('emptyText')], $ctx->props['emptyText'])];
        }

        return Element::h('div', [
            'class' => $ctx->classes('root', 'ak-collection'),
            'data-ak-items' => $ctx->mode === 'editor' ? (string) count($ctx->items) : null,
        ], $children);
    }
}

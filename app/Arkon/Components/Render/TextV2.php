<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** A paragraph or heading (h1–h4). Line breaks are kept (CSS pre-line), never turned into markup. */
final class TextV2 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $props = $ctx->props;

        return Element::h($props['element'], ['class' => $ctx->classes('root', 'ak-text2', 'ak-flow'), ...$ctx->editable('text', 'Write something')], $props['text']);
    }
}

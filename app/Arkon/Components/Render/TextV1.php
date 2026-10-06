<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

/** A paragraph or a level-2/3 heading. Line breaks are kept (CSS pre-line), never turned into markup. */
final class TextV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $props = $ctx->props;
        $class = 'ak-text'.($props['align'] === 'center' ? ' ak-text--center' : '');

        return Element::h($props['element'], ['class' => $class, ...$ctx->editable('text', 'Write something')], $props['text']);
    }
}

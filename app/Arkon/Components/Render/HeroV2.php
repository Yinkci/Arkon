<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;
use App\Arkon\Support\Text;

/**
 * Heading, text and buttons in a text column, with an optional image beside it.
 * The DOM order (text, then image) is the reading order; which side the image is
 * on, and stacking, come from the root's flex direction per breakpoint.
 */
final class HeroV2 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $props = $ctx->props;
        $image = $props['image'];
        $media = $image ? $ctx->media($image['assetId']) : null;
        $showText = ! Text::isBlank($props['text']) || $ctx->mode === 'editor';

        return Element::h(
            'section',
            ['class' => $ctx->classes('root', 'ak-hero2')],
            Element::h(
                'div',
                ['class' => $ctx->classes('content', 'ak-hero2__content')],
                Element::h($props['headingLevel'], ['class' => $ctx->classes('heading', 'ak-hero2__heading'), ...$ctx->editable('heading', 'Add a heading')], $props['heading']),
                $showText ? Element::h('p', ['class' => $ctx->classes('text', 'ak-hero2__text'), ...$ctx->editable('text', 'Add supporting text')], $props['text']) : null,
                $ctx->children !== [] ? Element::h('div', ['class' => $ctx->classes('actions', 'ak-hero2__actions')], $ctx->children) : null,
            ),
            $media ? Element::h('img', [
                'class' => $ctx->classes('media', 'ak-hero2__media'),
                ...$ctx->imageAttributes($media, $image['alt'], share: 0.5),
            ]) : null,
        );
    }
}

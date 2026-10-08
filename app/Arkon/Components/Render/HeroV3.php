<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;
use App\Arkon\Support\Text;

/**
 * Heading, text and buttons in a content area, with an optional image beside it.
 * The DOM order (text, then image) is the reading order; which side the image is
 * on, and stacking, come from the root's flex direction per breakpoint.
 *
 * v3 (from v2): a width set on the content area or the image wins over the equal
 * flex share (it becomes the item's size), so "Image width" works side by side too.
 */
final class HeroV3 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $props = $ctx->props;
        $image = $props['image'];
        $media = $image ? $ctx->media($image['assetId']) : null;
        $showText = ! Text::isBlank($props['text']) || $ctx->mode === 'editor';

        return Element::h(
            'section',
            [...$ctx->part('root'), 'class' => $ctx->classes('root', 'ak-hero3')],
            Element::h(
                'div',
                [...$ctx->part('content'), 'class' => $ctx->flexItemClasses('content', 'ak-hero3__content')],
                Element::h($props['headingLevel'], [...$ctx->part('heading'), 'class' => $ctx->classes('heading', 'ak-hero3__heading'), ...$ctx->editable('heading', 'Add a heading')], $props['heading']),
                $showText ? Element::h('p', [...$ctx->part('text'), 'class' => $ctx->classes('text', 'ak-hero3__text'), ...$ctx->editable('text', 'Add supporting text')], $props['text']) : null,
                $ctx->children !== [] ? Element::h('div', [...$ctx->part('actions'), 'class' => $ctx->classes('actions', 'ak-hero3__actions')], $ctx->children) : null,
            ),
            $media ? Element::h('img', [
                ...$ctx->part('media'),
                'class' => $ctx->flexItemClasses('media', 'ak-hero3__media'),
                ...$ctx->imageAttributes($media, $image['alt'], share: 0.5),
            ]) : null,
        );
    }
}

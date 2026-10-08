<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;
use App\Arkon\Support\Text;

/**
 * An image with optional caption: responsive variants, intrinsic size (no layout
 * shift), fit and crop position from the style prop, and loading priority (see
 * RenderContext::imageAttributes).
 */
final class ImageV3 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $props = $ctx->props;
        $image = $props['image'];
        $media = $image ? $ctx->media($image['assetId']) : null;
        $showCaption = ! Text::isBlank($props['caption']) || $ctx->mode === 'editor';

        return Element::h(
            'figure',
            [...$ctx->part('root'), 'class' => $ctx->classes('root', 'ak-img2', 'ak-flow')],
            $media ? Element::h('img', [
                ...$ctx->part('media'),
                'class' => $ctx->classes('media', 'ak-img2__media'),
                ...$ctx->imageAttributes($media, $image['alt'], $props['loading']),
            ]) : null,
            // The editor shows an empty-image placeholder; publishing an image block without an image is blocked.
            ! $media && $ctx->mode === 'editor' ? Element::h('span', ['class' => 'ak-image__empty', 'data-ak-placeholder' => 'Choose an image in the inspector']) : null,
            $showCaption ? Element::h('figcaption', [...$ctx->part('caption'), 'class' => $ctx->classes('caption', 'ak-img2__caption'), ...$ctx->editable('caption', 'Add a caption')], $props['caption']) : null,
        );
    }
}

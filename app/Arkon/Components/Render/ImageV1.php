<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;
use App\Arkon\Support\Text;

/**
 * An image with optional caption. Width and height come from the media record
 * (no layout shift). A top-level image in the first section is the likely LCP
 * element; every other image is lazy-loaded.
 */
final class ImageV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $props = $ctx->props;
        $image = $props['image'];
        $media = $image ? $ctx->media($image['assetId']) : null;
        $isLikelyLcp = $ctx->sectionIndex === 0;
        $showCaption = ! Text::isBlank($props['caption']) || $ctx->mode === 'editor';

        return Element::h(
            'figure',
            ['class' => "ak-image ak-image--{$props['size']}"],
            $media ? Element::h('img', [
                'src' => $media['url'],
                'alt' => $image['alt'],
                'width' => $media['width'],
                'height' => $media['height'],
                'decoding' => 'async',
                'loading' => $isLikelyLcp ? null : 'lazy',
                'fetchpriority' => $isLikelyLcp ? 'high' : null,
            ]) : null,
            // The editor shows an empty-image placeholder; publishing an image block without an image is blocked.
            ! $media && $ctx->mode === 'editor' ? Element::h('span', ['class' => 'ak-image__empty', 'data-ak-placeholder' => 'Choose an image in the inspector']) : null,
            $showCaption ? Element::h('figcaption', $ctx->editable('caption', 'Add a caption'), $props['caption']) : null,
        );
    }
}

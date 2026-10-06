<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;
use App\Arkon\Support\Text;

final class HeroV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $props = $ctx->props;
        $image = $props['image'];
        $media = $image ? $ctx->media($image['assetId']) : null;
        $isLikelyLcp = $ctx->sectionIndex === 0;
        $showText = ! Text::isBlank($props['text']) || $ctx->mode === 'editor';

        return Element::h(
            'section',
            ['class' => $media ? 'ak-hero ak-hero--media' : 'ak-hero'],
            Element::h($props['headingLevel'], ['class' => 'ak-hero__heading', ...$ctx->editable('heading', 'Add a heading')], $props['heading']),
            $showText ? Element::h('p', ['class' => 'ak-hero__text', ...$ctx->editable('text', 'Add supporting text')], $props['text']) : null,
            $media ? Element::h('img', [
                'class' => 'ak-hero__image',
                'src' => $media['url'],
                'alt' => $image['alt'],
                'width' => $media['width'],
                'height' => $media['height'],
                'decoding' => 'async',
                // The first section's image is the likely LCP element: load it eagerly and early.
                'loading' => $isLikelyLcp ? null : 'lazy',
                'fetchpriority' => $isLikelyLcp ? 'high' : null,
            ]) : null,
        );
    }
}

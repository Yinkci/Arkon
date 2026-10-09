<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

final class LogoV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $image = $ctx->props['image'];
        $media = $image ? $ctx->media($image['assetId']) : null;

        return Element::h('a', ['class' => $ctx->classes('root', 'ak-logo'), 'href' => $ctx->props['href'], 'aria-label' => $ctx->props['label']],
            $media ? Element::h('img', [...$ctx->imageAttributes($media, $image['alt'], 'lazy', .15), 'loading' => 'eager', 'fetchpriority' => null, 'class' => $ctx->classes('media', 'ak-logo__image'), ...$ctx->part('media')]) : ($ctx->mode === 'editor' ? 'Choose logo image' : null));
    }
}

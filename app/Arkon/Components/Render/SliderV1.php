<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

final class SliderV1 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $id = 'ak-slider-'.substr(hash('sha256', $ctx->occurrence.$ctx->node['id']), 0, 14);
        $count = count($ctx->children);
        if ($ctx->mode === 'production') {
            foreach ($ctx->children as $i => $slide) {
                $slide->attrs['data-active'] = $i === 0 ? 'true' : 'false';
                $slide->attrs['aria-hidden'] = $i === 0 ? null : 'true';
                $slide->attrs['inert'] = $i === 0 ? null : true;
            }
        }

        return Element::h('section', ['class' => $ctx->classes('root', 'ak-slider', $ctx->mode === 'editor' ? 'ak-slider--editor' : null),
            'role' => 'region', 'aria-roledescription' => 'carousel', 'aria-label' => $ctx->props['label'],
            'data-arkon-slider' => $ctx->mode === 'production' ? $id : null, 'data-autoplay' => $ctx->props['autoplay'] ? 'true' : 'false', 'data-interval' => $ctx->props['interval']],
            Element::h('div', ['class' => 'ak-slider__slides', 'id' => $id], $ctx->children),
            Element::h('div', ['class' => 'ak-slider__controls', 'hidden' => true],
                Element::h('button', ['type' => 'button', 'data-slide-step' => '-1', 'aria-label' => 'Previous slide', 'aria-controls' => $id], IconV1::svg('arrow-up')),
                array_map(fn ($i) => Element::h('button', ['type' => 'button', 'data-slide-index' => (string) $i, 'aria-label' => 'Show slide '.($i + 1), 'aria-controls' => $id, 'aria-current' => $i === 0 ? 'true' : null], (string) ($i + 1)), range(0, max(0, $count - 1))),
                Element::h('button', ['type' => 'button', 'data-slide-step' => '1', 'aria-label' => 'Next slide', 'aria-controls' => $id], IconV1::svg('arrow-right')),
                Element::h('button', ['type' => 'button', 'data-slide-pause' => '', 'hidden' => ! $ctx->props['autoplay'], 'aria-pressed' => 'false'], 'Pause autoplay')),
            Element::h('span', ['class' => 'ak-visually-hidden', 'data-slide-status' => '', 'aria-live' => 'polite', 'aria-atomic' => 'true']));
    }
}

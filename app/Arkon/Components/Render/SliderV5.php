<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;

final class SliderV5 implements ComponentRenderer
{
    public function render(RenderContext $ctx): Element
    {
        $id = 'ak-slider-'.substr(hash('sha256', $ctx->occurrence.$ctx->node['id']), 0, 14);
        $count = count($ctx->children);

        foreach ($ctx->children as $i => $slide) {
            $slide->attrs['data-active'] = $i === 0 ? 'true' : 'false';
            $slide->attrs['aria-hidden'] = $i === 0 ? null : 'true';
            $slide->attrs['inert'] = $i === 0 ? null : true;
        }

        return Element::h('section', ['class' => $ctx->classes('root', 'ak-slider', 'ak-slider--v2', 'ak-slider--v4', 'ak-slider--v5', 'ak-slider--arrows-'.$ctx->props['arrowPlacement'], ! $ctx->props['showPauseControl'] ? 'ak-slider--quiet-playback' : null, 'ak-slider--'.$ctx->props['pagination'], 'ak-slider--controls-'.$ctx->props['controlsAlign'], 'ak-slider--tone-'.$ctx->props['controlsTone'], $ctx->mode === 'editor' ? 'ak-slider--editing' : null),
            'data-editor-slider' => $ctx->mode === 'editor' ? 'true' : null, 'role' => 'region', 'aria-roledescription' => 'carousel', 'aria-label' => $ctx->props['label'],
            'data-arkon-slider' => $ctx->mode === 'production' ? $id : null, 'data-autoplay' => $ctx->props['autoplay'] ? 'true' : 'false', 'data-pause-hover' => $ctx->props['pauseOnHover'] ? 'true' : 'false', 'data-transition' => $ctx->props['transition'], 'data-transition-duration' => $ctx->props['transitionDuration'], 'data-interval' => $ctx->props['interval']],
            Element::h('div', ['class' => 'ak-slider__slides', 'id' => $id], $ctx->children),
            Element::h('div', ['class' => 'ak-slider__controls', 'hidden' => $ctx->mode !== 'editor' || $count < 2],
                $ctx->props['arrows'] ? Element::h('button', ['type' => 'button', 'data-slide-step' => '-1', 'aria-label' => 'Previous slide', 'aria-controls' => $id], IconV1::svg('arrow-up')) : null,
                $ctx->props['pagination'] !== 'none' ? array_map(fn ($i) => Element::h('button', ['type' => 'button', 'data-slide-index' => (string) $i, 'aria-label' => 'Show slide '.($i + 1), 'aria-controls' => $id, 'aria-current' => $i === 0 ? 'true' : null], Element::h('span', ['class' => 'ak-slider__marker', 'aria-hidden' => 'true'], (string) ($i + 1))), range(0, max(0, $count - 1))) : null,
                $ctx->props['arrows'] ? Element::h('button', ['type' => 'button', 'data-slide-step' => '1', 'aria-label' => 'Next slide', 'aria-controls' => $id], IconV1::svg('arrow-right')) : null,
                ! $ctx->props['arrows'] && $ctx->props['pagination'] === 'none' ? Element::h('button', ['class' => 'ak-slider__fallback', 'type' => 'button', 'data-slide-step' => '1', 'aria-label' => 'Next slide', 'aria-controls' => $id], IconV1::svg('arrow-right')) : null,
                $ctx->mode === 'editor' && $count > 1 ? Element::h('button', ['class' => 'ak-slider__editor-play', 'type' => 'button', 'data-editor-play' => '', 'aria-pressed' => 'false'], 'Play slideshow') : null,
                Element::h('button', ['type' => 'button', 'data-slide-pause' => '', 'aria-label' => 'Pause or resume autoplay', 'hidden' => ! $ctx->props['autoplay'] || $ctx->mode === 'editor', 'aria-pressed' => 'false'], 'Pause autoplay')),
            Element::h('span', ['class' => 'ak-visually-hidden', 'data-slide-status' => '', 'aria-live' => 'polite', 'aria-atomic' => 'true']));
    }
}

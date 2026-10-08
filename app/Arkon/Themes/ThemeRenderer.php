<?php

namespace App\Arkon\Themes;

use App\Arkon\Components\Render\ComponentRenderer;
use App\Arkon\Components\Render\RenderContext;
use App\Arkon\Renderer\Element;

final class ThemeRenderer implements ComponentRenderer
{
    public function __construct(private readonly array $package) {}

    public function render(RenderContext $ctx): Element
    {
        $render = function (array|string $tree, bool $root = false) use (&$render, $ctx): Element|string|null {
            if (is_string($tree)) {
                return $tree;
            }
            $source = $tree['attrs'];
            $part = $source['data-part'] ?? null;
            $attrs = isset($source['aria-label']) ? ['aria-label' => $source['aria-label']] : [];
            $classes = $source['class'] ?? '';
            if ($root) {
                $classes = trim($classes.' '.Template::scope($this->package['manifest']));
            }
            if (isset($source['data-variant'])) {
                $classes .= ' variant-'.$ctx->props[$source['data-variant']];
            }
            if ($part !== null) {
                $attrs = [...$attrs, ...$ctx->part($part)];
                $classes = $ctx->classes($part, $classes);
            }
            $attrs['class'] = $classes;
            if (isset($source['data-image'])) {
                $image = $ctx->props[$source['data-image']];
                $media = $image ? $ctx->media($image['assetId']) : null;
                if (! $media) {
                    return null;
                }

                return Element::h('img', [...$attrs, ...$ctx->imageAttributes($media, $image['alt'], 'auto')]);
            }
            if (isset($source['data-field'])) {
                $field = $source['data-field'];
                $inline = $this->package['manifest']['inlineFields'][$field] ?? null;
                if ($inline !== null) {
                    $attrs = [...$attrs, ...$ctx->editable($field)];
                }

                return Element::h($tree['tag'], $attrs, $ctx->props[$field]);
            }

            return Element::h($tree['tag'], $attrs, array_map(fn ($child) => $render($child), $tree['children']));
        };

        return $render($this->package['template'], true);
    }
}

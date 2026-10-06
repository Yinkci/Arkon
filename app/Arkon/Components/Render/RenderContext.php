<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;
use App\Arkon\Renderer\TextNode;
use Closure;

final class RenderContext
{
    /**
     * @param  array  $node  the node in raw JSON form
     * @param  array  $props  parsed props (defaults applied)
     * @param  list<Element|TextNode>  $children
     * @param  'production'|'editor'  $mode
     * @param  int  $sectionIndex  position among the page root's children (0 = likely above the fold)
     * @param  Closure(string): (array{id: string, url: string, width: int, height: int, mime: string}|null)  $media
     */
    public function __construct(
        public readonly array $node,
        public readonly array $props,
        public readonly array $children,
        public readonly string $mode,
        public readonly int $sectionIndex,
        private readonly Closure $media,
    ) {}

    /** @return array{id: string, url: string, width: int, height: int, mime: string}|null */
    public function media(string $assetId): ?array
    {
        return ($this->media)($assetId);
    }

    /**
     * Marks an element as inline-editable for `$prop`. Editor-only attributes in
     * editor mode, nothing in production.
     *
     * @return array<string, string|null>
     */
    public function editable(string $prop, ?string $placeholder = null): array
    {
        return $this->mode === 'editor' ? ['data-ak-prop' => $prop, 'data-ak-placeholder' => $placeholder] : [];
    }
}

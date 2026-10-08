<?php

namespace App\Arkon\Components\Render;

use App\Arkon\Renderer\Element;
use App\Arkon\Renderer\TextNode;
use App\Arkon\Style\StyleSheet;
use Closure;

final class RenderContext
{
    /**
     * @param  array  $node  the node in raw JSON form
     * @param  array  $props  parsed props (defaults applied)
     * @param  list<Element|TextNode>  $children
     * @param  'production'|'editor'  $mode
     * @param  int  $sectionIndex  position among the page root's children (0 = likely above the fold), -1 when nested
     * @param  Closure(string): (array{id: string, url: string, width: int, height: int, mime: string, variants?: list<array{url: string, width: int}>}|null)  $media
     * @param  int  $topIndex  position of the top-level block this node is in (-1 for the page itself)
     * @param  float  $widthFraction  estimated share of the content width this node gets on wide screens
     * @param  (Closure(): bool)|null  $claimPriority  true for the first image in the first two top-level blocks, once
     * @param  (Closure(): void)|null  $notePriority  told about every image that gets high priority (the likely LCP)
     */
    public function __construct(
        public readonly array $node,
        public readonly array $props,
        public readonly array $children,
        public readonly string $mode,
        public readonly int $sectionIndex,
        private readonly Closure $media,
        public readonly ?StyleSheet $styles = null,
        public readonly int $topIndex = -1,
        public readonly float $widthFraction = 1.0,
        private readonly ?Closure $claimPriority = null,
        private readonly ?Closure $notePriority = null,
    ) {}

    /** @return array{id: string, url: string, width: int, height: int, mime: string, variants?: list<array{url: string, width: int}>}|null */
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

    /**
     * Editor-only: which style slot (part) of the component an element is, so the editor
     * can select and highlight that part. Nothing in production.
     *
     * @return array<string, string>
     */
    public function part(string $slot): array
    {
        return $this->mode === 'editor' ? ['data-ak-part' => $slot] : [];
    }

    /** The generated class for one style slot of this node's `style` prop, if it sets anything. */
    public function styleClass(string $slot, bool $sizedFlexItem = false): ?string
    {
        $style = $this->props['style'] ?? null;

        return is_array($style) && $this->styles ? $this->styles->classFor($style[$slot] ?? null, $sizedFlexItem) : null;
    }

    /** Like classes(), for a flex item whose own width (when set) replaces its flex share. */
    public function flexItemClasses(string $slot, ?string ...$classes): string
    {
        return implode(' ', array_filter([...$classes, $this->styleClass($slot, true)], fn ($c) => $c !== null && $c !== ''));
    }

    /** A class attribute from fixed classes plus a style slot's generated class. */
    public function classes(string $slot, ?string ...$classes): string
    {
        return implode(' ', array_filter([...$classes, $this->styleClass($slot)], fn ($c) => $c !== null && $c !== ''));
    }

    /**
     * Attributes for an image: intrinsic size (no layout shift), responsive
     * variants with `sizes`, and loading priority. Only the first image within the
     * first two top-level blocks is fetched eagerly with high priority (the likely LCP
     * element); everything else is lazy. `$loading` overrides: eager | lazy | auto.
     *
     * @param  array{url: string, width: int, height: int, variants?: list<array{url: string, width: int}>}  $media
     * @return array<string, string|int|null>
     */
    public function imageAttributes(array $media, string $alt, string $loading = 'auto', float $share = 1.0): array
    {
        $priority = match ($loading) {
            'eager' => true,
            'lazy' => false,
            default => $this->topIndex >= 0 && $this->topIndex <= 1 && $this->claimPriority !== null && ($this->claimPriority)(),
        };
        if ($priority && $this->notePriority !== null) {
            ($this->notePriority)();
        }
        $variants = $media['variants'] ?? [];
        $srcset = $variants === [] ? null : implode(', ', array_map(fn ($v) => "{$v['url']} {$v['width']}w", $variants));
        // Wide screens: the content column (72rem ≈ 1152px) times this block's share; below the tablet breakpoint, full width.
        $wide = (int) max(160, round(1152 * min(1.0, $this->widthFraction * $share)));

        return [
            'src' => $media['url'],
            'srcset' => $srcset,
            'sizes' => $srcset === null ? null : "(max-width: 899px) 100vw, {$wide}px",
            'alt' => $alt,
            'width' => $media['width'],
            'height' => $media['height'],
            'decoding' => 'async',
            'loading' => $priority ? null : 'lazy',
            'fetchpriority' => $priority ? 'high' : null,
        ];
    }
}

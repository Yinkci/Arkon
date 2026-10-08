<?php

namespace App\Arkon\Style;

use App\Arkon\Support\Rules;
use Closure;

/**
 * Turns validated style values into CSS. Each styled slot gets one class named
 * after a hash of its declarations, so identical styles share a rule and the
 * output is deterministic. Only allowlisted properties are written, with values
 * mapped from the registry (enums, tokens) or re-checked formats (lengths,
 * colours); nothing from content is copied into CSS unchecked.
 *
 * Rules are emitted in three passes (all screens, then tablet, then mobile media
 * queries), so a smaller screen inherits a larger one unless it overrides.
 */
final class StyleSheet
{
    /** @var array<string, array{base: string, tablet: string, mobile: string}> class → declarations */
    private array $rules = [];

    /**
     * @param  Closure(string): ?string  $imageUrl  media asset id → URL for background images (null: not available)
     * @param  bool  $flexBasis  direction settings also write --ak-basis (false only to reproduce output made before it existed)
     */
    public function __construct(private readonly Closure $imageUrl, private readonly bool $flexBasis = true) {}

    /**
     * The class for one slot's style, or null when it sets nothing.
     *
     * @param  bool  $sizedFlexItem  the slot is a flex item sharing its row (e.g. hero v3's parts): a set
     *                               width becomes its size (flex 0 1 auto), "auto" gives the share back
     */
    public function classFor(?array $slotStyle, bool $sizedFlexItem = false): ?string
    {
        if (! $slotStyle) {
            return null;
        }
        $declarations = [];
        foreach (StyleSchema::BREAKPOINTS as $breakpoint) {
            $declarations[$breakpoint] = $this->declarations($slotStyle[$breakpoint] ?? [], $sizedFlexItem);
        }
        if (implode('', $declarations) === '') {
            return null;
        }
        $class = 'ak-s'.substr(hash('sha256', implode('|', $declarations)), 0, 10);
        $this->rules[$class] = $declarations;

        return $class;
    }

    /**
     * The class for a slot's animation settings (the "motion" group), or null when it has no
     * animation. Kept apart from classFor() so the renderer can leave it off a block whose
     * animation is suppressed (see PageRenderer); the trigger is markup, not CSS.
     */
    public function motionClassFor(?array $slotStyle): ?string
    {
        if (! $slotStyle) {
            return null;
        }
        $declarations = [];
        foreach (StyleSchema::BREAKPOINTS as $breakpoint) {
            $out = [];
            foreach (StyleSchema::properties() as $property => $definition) {
                if ($definition['group'] !== 'motion' || $property === 'animationTrigger' || ! array_key_exists($property, $slotStyle[$breakpoint] ?? [])) {
                    continue;
                }
                $css = self::value($definition, $slotStyle[$breakpoint][$property]);
                if ($css !== null) {
                    $out[] = "{$definition['css']}:{$css}";
                }
            }
            $declarations[$breakpoint] = implode(';', $out);
        }
        if (implode('', $declarations) === '') {
            return null;
        }
        $class = 'ak-m'.substr(hash('sha256', implode('|', $declarations)), 0, 10);
        $this->rules[$class] = $declarations;

        return $class;
    }

    public function css(): string
    {
        $out = '';
        foreach (StyleSchema::BREAKPOINTS as $breakpoint) {
            $block = '';
            foreach ($this->rules as $class => $declarations) {
                if ($declarations[$breakpoint] !== '') {
                    $block .= ".{$class}{{$declarations[$breakpoint]}}";
                }
            }
            if ($block === '') {
                continue;
            }
            $out .= $breakpoint === 'base' ? $block : '@media (max-width:'.Rules::get("style.breakpoints.{$breakpoint}")."px){{$block}}";
        }

        return $out;
    }

    /** Declarations for one breakpoint, in registry order. */
    private function declarations(array $values, bool $sizedFlexItem = false): string
    {
        $out = [];
        foreach (StyleSchema::properties() as $property => $definition) {
            // Animation settings have their own class (motionClassFor).
            if (! array_key_exists($property, $values) || in_array($property, ['backgroundImage', 'backgroundOverlay'], true) || $definition['group'] === 'motion') {
                continue;
            }
            $css = self::value($definition, $values[$property]);
            if ($css !== null) {
                $out[] = "{$definition['css']}:{$css}";
            }
        }
        // Side by side, parts share the row equally (basis 0%); stacked, each keeps its own height
        // (a percentage basis would fall back to the content size and ignore a set height).
        if ($this->flexBasis && is_string($values['direction'] ?? null) && isset(StyleSchema::properties()['direction']['values'][$values['direction']])) {
            $out[] = '--ak-basis:'.(str_starts_with($values['direction'], 'row') ? '0%' : 'auto');
        }
        if ($sizedFlexItem && is_string($values['width'] ?? null) && self::value(StyleSchema::properties()['width'], $values['width']) !== null) {
            $out[] = $values['width'] === 'auto' ? 'flex:1 1 var(--ak-basis)' : 'flex:0 1 auto';
        }
        // Overlay and image share one background-image: the overlay is a flat gradient layered on top.
        $layers = [];
        if (isset($values['backgroundOverlay']) && ($overlay = self::value(StyleSchema::properties()['backgroundOverlay'], $values['backgroundOverlay'])) !== null) {
            $layers[] = "linear-gradient({$overlay},{$overlay})";
        }
        if (is_array($values['backgroundImage'] ?? null) && ($url = self::url(($this->imageUrl)($values['backgroundImage']['assetId']))) !== null) {
            $layers[] = $url;
        }
        if ($layers !== []) {
            $out[] = 'background-image:'.implode(',', $layers);
            if (! isset($values['backgroundSize'])) {
                $out[] = 'background-size:cover';
            }
            if (! isset($values['backgroundPosition'])) {
                $out[] = 'background-position:center';
            }
        }

        return implode(';', $out);
    }

    /** CSS for one validated value, or null if it does not pass the checks again. */
    public static function value(array $definition, mixed $value): ?string
    {
        if (! is_string($value) || StyleSchema::valueProblem($definition, $value) !== null) {
            return null;
        }
        if (str_starts_with($value, '@')) {
            return self::tokenVar(substr($value, 1));
        }
        if (in_array($value, $definition['keywords'] ?? [], true)) {
            return $value;
        }

        return match ($definition['kind']) {
            'enum' => (string) $definition['values'][$value],
            'color' => strtolower($value),
            'ratio' => str_replace('/', ' / ', $value),
            'columns' => preg_match('/^[1-6]$/D', $value) === 1
                ? "repeat({$value},minmax(0,1fr))"
                : implode(' ', array_map(fn ($track) => "minmax(0,{$track})", explode(' ', $value))),
            'font' => (string) Rules::get('style.fonts')[$value],
            'shadow' => (string) Rules::get('style.shadows')[$value],
            default => $value,
        };
    }

    /** `color.primary` → `var(--ak-t-color-primary)` */
    public static function tokenVar(string $ref): string
    {
        return 'var(--ak-t-'.str_replace('.', '-', $ref).')';
    }

    /** A same-origin or signed media URL as a CSS url(); anything unexpected is dropped. */
    private static function url(?string $url): ?string
    {
        if ($url === null || preg_match('#^(?:/(?!/)|https?://)[!-~]*$#D', $url) !== 1) {
            return null;
        }

        return 'url("'.str_replace(['\\', '"', '(', ')'], ['%5C', '%22', '%28', '%29'], $url).'")';
    }
}

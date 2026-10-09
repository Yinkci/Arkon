<?php

namespace App\Arkon\Style;

use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;

/**
 * Validation of the shared styling model (`style` in resources/arkon/rules.json).
 * The TypeScript twin is resources/js/arkon/style/schema.ts; tests/Conformance holds
 * both to the same results.
 *
 * A style value is {slot: {base?, tablet?, mobile?}}, each breakpoint a map of
 * property → value. Which properties a slot accepts is declared by the component
 * manifest (`slots.<name>.groups` and `.properties`). Every value is checked against
 * an allowlist: lengths in allowed units within bounds, hex colours, enums, ratios,
 * column tracks and design-token references. Nothing is ever passed through as CSS.
 */
final class StyleSchema
{
    public const BREAKPOINTS = ['base', 'tablet', 'mobile'];

    /** @return array<string, array> property → definition, in output order */
    public static function properties(): array
    {
        return Rules::get('style.properties');
    }

    /** @return list<string> the properties a slot accepts, in registry order */
    public static function allowed(array $slot): array
    {
        $groups = $slot['groups'] ?? [];
        $extra = $slot['properties'] ?? [];

        return array_values(array_filter(
            array_keys(self::properties()),
            fn ($key) => in_array(self::properties()[$key]['group'], $groups, true) || in_array($key, $extra, true),
        ));
    }

    /**
     * Validates a style prop for a field ({type: style, slots}) and returns it in
     * internal form. Issues are appended with paths under `$at`.
     */
    public static function parse(array $field, mixed $value, string $at, array &$issues, bool $raw): ?array
    {
        if (! Json::isObject($value) && ($raw || $value !== [])) {
            $issues[] = ['path' => $at, 'message' => Rules::message('expectedObject')];

            return null;
        }
        $slots = $field['slots'] ?? [];
        $out = [];
        foreach (Json::entries($value) as $slotName => $slotValue) {
            $slotName = (string) $slotName;
            $slotPath = "{$at}.{$slotName}";
            if (! array_key_exists($slotName, $slots)) {
                $issues[] = ['path' => $slotPath, 'message' => Rules::message('unrecognizedKey', ['key' => $slotName])];

                continue;
            }
            if (! Json::isObject($slotValue) && ($raw || $slotValue !== [])) {
                $issues[] = ['path' => $slotPath, 'message' => Rules::message('expectedObject')];

                continue;
            }
            $allowed = self::allowed($slots[$slotName]);
            foreach (Json::entries($slotValue) as $breakpoint => $declarations) {
                $breakpoint = (string) $breakpoint;
                $bpPath = "{$slotPath}.{$breakpoint}";
                if (! in_array($breakpoint, self::BREAKPOINTS, true)) {
                    $issues[] = ['path' => $bpPath, 'message' => Rules::message('unrecognizedKey', ['key' => $breakpoint])];

                    continue;
                }
                if (! Json::isObject($declarations) && ($raw || $declarations !== [])) {
                    $issues[] = ['path' => $bpPath, 'message' => Rules::message('expectedObject')];

                    continue;
                }
                foreach (Json::entries($declarations) as $property => $propertyValue) {
                    $property = (string) $property;
                    $path = "{$bpPath}.{$property}";
                    $definition = self::properties()[$property] ?? null;
                    if ($definition === null) {
                        $issues[] = ['path' => $path, 'message' => Rules::message('unrecognizedKey', ['key' => $property])];

                        continue;
                    }
                    if (! in_array($property, $allowed, true)) {
                        $issues[] = ['path' => $path, 'message' => Rules::message('styleNotAllowed', ['label' => $definition['label']])];

                        continue;
                    }
                    if (($definition['baseOnly'] ?? false) && $breakpoint !== 'base') {
                        $issues[] = ['path' => $path, 'message' => Rules::message('styleBaseOnly', ['label' => $definition['label']])];

                        continue;
                    }
                    $problem = self::valueProblem($definition, $propertyValue, $raw);
                    if ($problem !== null) {
                        $issues[] = ['path' => $path, 'message' => $problem];

                        continue;
                    }
                    $out[$slotName][$breakpoint][$property] = $definition['kind'] === 'image' ? Json::entries($propertyValue) : $propertyValue;
                }
            }
        }

        return $out;
    }

    /** Null when the value is acceptable for the property, otherwise the message. */
    public static function valueProblem(array $definition, mixed $value, bool $raw = true): ?string
    {
        $label = $definition['label'];
        $invalid = fn () => Rules::message('styleInvalid', ['label' => $label, 'expected' => self::expected($definition)]);
        $kind = $definition['kind'];

        if ($kind === 'image') {
            if (! Json::isObject($value) && ($raw || ! is_array($value))) {
                return Rules::message('expectedAsset');
            }
            $entries = Json::entries($value);
            if (array_keys($entries) !== ['assetId'] || ! Rules::matches('uuid', $entries['assetId'])) {
                return Rules::message('expectedAsset');
            }

            return null;
        }
        if (! is_string($value)) {
            return $invalid();
        }
        if (str_starts_with($value, '@')) {
            return self::tokenProblem($definition, $value) ?? null;
        }
        if (in_array($value, $definition['keywords'] ?? [], true)) {
            return null;
        }

        switch ($kind) {
            case 'enum':
                return array_key_exists($value, $definition['values']) ? null : $invalid();

            case 'length':
                if ($value === '0') {
                    return null;
                }
                if (! Rules::matches('styleLength', $value)) {
                    return $invalid();
                }
                preg_match('/^(-?[0-9.]+)(.+)$/D', $value, $m);
                $bounds = Rules::get("style.lengths.{$definition['lengths']}")[$m[2]] ?? null;
                if ($bounds === null) {
                    return $invalid();
                }
                $number = (float) $m[1];

                return $number < $bounds[0] || $number > $bounds[1]
                    ? Rules::message('styleOutOfRange', ['label' => $label, 'min' => self::num($bounds[0]), 'max' => self::num($bounds[1]), 'unit' => $m[2]])
                    : null;

            case 'number':
                if (! Rules::matches('styleNumber', $value)) {
                    return $invalid();
                }
                $number = (float) $value;

                return $number < $definition['min'] || $number > $definition['max']
                    ? Rules::message('styleOutOfRange', ['label' => $label, 'min' => self::num($definition['min']), 'max' => self::num($definition['max']), 'unit' => ''])
                    : null;

            case 'time':
                // Milliseconds only (600ms), within the property's bounds.
                if (! Rules::matches('styleTime', $value)) {
                    return $invalid();
                }
                $number = (int) substr($value, 0, -2);

                return $number < $definition['min'] || $number > $definition['max']
                    ? Rules::message('styleOutOfRange', ['label' => $label, 'min' => self::num($definition['min']), 'max' => self::num($definition['max']), 'unit' => 'ms'])
                    : null;

            case 'gradient':
                return preg_match('/^(?:0|45|90|135|180|225|270|315)deg #[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})? #[0-9a-fA-F]{6}(?:[0-9a-fA-F]{2})?$/D', $value) === 1 ? null : $invalid();

            case 'color':
                return $value === 'transparent' || Rules::matches('styleColor', $value) ? null : $invalid();

            case 'ratio':
                return Rules::matches('styleRatio', $value) ? null : $invalid();

            case 'columns':
                if (preg_match('/^[1-6]$/D', $value) === 1) {
                    return null;
                }
                $tracks = explode(' ', $value);
                if (count($tracks) < 2 || count($tracks) > 6) {
                    return $invalid();
                }
                foreach ($tracks as $track) {
                    if (! Rules::matches('styleTrack', $track) || (float) $track <= 0 || (float) $track > 20) {
                        return $invalid();
                    }
                }

                return null;

            case 'font':
                return array_key_exists($value, Rules::get('style.fonts')) ? null : $invalid();

            case 'shadow':
                return array_key_exists($value, Rules::get('style.shadows')) ? null : $invalid();
        }

        throw new \InvalidArgumentException("Unknown style kind {$kind}");
    }

    private static function tokenProblem(array $definition, string $value): ?string
    {
        $invalid = Rules::message('styleInvalid', ['label' => $definition['label'], 'expected' => self::expected($definition)]);
        if (! Rules::matches('styleToken', $value)) {
            return $invalid;
        }
        [$group, $name] = explode('.', substr($value, 1), 2);
        if (! in_array($group, $definition['tokens'] ?? [], true)) {
            return $invalid;
        }

        return array_key_exists($name, Rules::get("tokens.{$group}.values")) ? null : Rules::message('unknownToken', ['token' => $value]);
    }

    /** A short description of the accepted values (same wording as the TypeScript twin). */
    public static function expected(array $definition): string
    {
        $parts = [match ($definition['kind']) {
            'enum' => 'one of '.implode(', ', array_map('strval', array_keys($definition['values']))),
            'length' => 'a length in '.implode(', ', array_keys(Rules::get("style.lengths.{$definition['lengths']}"))),
            'number' => 'a number',
            'time' => 'a duration in milliseconds such as 600ms',
            'color' => 'a hex colour such as #1d4ed8, or transparent',
            'ratio' => 'a ratio such as 16/9',
            'columns' => 'a column count (1-6) or 2-6 fractions such as 1fr 2fr',
            'font' => 'one of '.implode(', ', array_keys(Rules::get('style.fonts'))),
            'shadow' => 'one of '.implode(', ', array_keys(Rules::get('style.shadows'))),
            'image' => 'an image reference',
            'gradient' => 'an angle (0,45,90,135,180,225,270,315deg) and two hex colours, e.g. 90deg #102030ff #10203000',
        }];
        foreach ($definition['keywords'] ?? [] as $keyword) {
            $parts[] = $keyword;
        }
        foreach ($definition['tokens'] ?? [] as $group) {
            $parts[] = "a @{$group} token";
        }

        return implode(' or ', $parts);
    }

    /** Media asset ids used by a parsed style value (background images). */
    public static function assetIds(array $style): array
    {
        $ids = [];
        foreach ($style as $breakpoints) {
            foreach ((array) $breakpoints as $declarations) {
                $image = $declarations['backgroundImage'] ?? null;
                if (is_array($image) && is_string($image['assetId'] ?? null)) {
                    $ids[] = $image['assetId'];
                }
            }
        }

        return $ids;
    }

    /** Numbers in messages: like JavaScript's String(n) for the bounds used here. */
    private static function num(int|float $n): string
    {
        return is_int($n) || floor($n) === $n ? (string) (int) $n : rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }
}

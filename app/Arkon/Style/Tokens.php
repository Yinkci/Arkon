<?php

namespace App\Arkon\Style;

use App\Arkon\Support\Json;
use App\Arkon\Support\Rules;

/**
 * Design tokens: fixed slots (`tokens` in rules.json) whose values a site can
 * change. A token set is {group: {name: value}}; anything left out keeps its
 * default. The TypeScript twin is resources/js/arkon/style/tokens.ts.
 */
final class Tokens
{
    /** @return array<string, array<string, string>> group → name → default value */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::groups() as $group => $definition) {
            $out[$group] = $definition['values'];
        }

        return $out;
    }

    /** @return array<string, array> group → definition (label, kind, lengths?, values) */
    public static function groups(): array
    {
        return array_filter(Rules::get('tokens'), fn ($value, $key) => $key !== '$comment', ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Validates a (partial) token set in raw JSON form and returns it in internal form.
     *
     * @return array{0: array|null, 1: list<array{path: string, message: string}>}
     */
    public static function parse(mixed $value): array
    {
        $issues = [];
        if (! Json::isObject($value)) {
            return [null, [['path' => '', 'message' => Rules::message('expectedObject')]]];
        }
        $groups = self::groups();
        $out = [];
        foreach (Json::entries($value) as $group => $names) {
            $group = (string) $group;
            if (! isset($groups[$group])) {
                $issues[] = ['path' => $group, 'message' => Rules::message('unrecognizedKey', ['key' => $group])];

                continue;
            }
            if (! Json::isObject($names)) {
                $issues[] = ['path' => $group, 'message' => Rules::message('expectedObject')];

                continue;
            }
            foreach (Json::entries($names) as $name => $tokenValue) {
                $name = (string) $name;
                if (! array_key_exists($name, $groups[$group]['values'])) {
                    $issues[] = ['path' => "{$group}.{$name}", 'message' => Rules::message('unrecognizedKey', ['key' => $name])];

                    continue;
                }
                $problem = StyleSchema::valueProblem(self::definition($group, $name), $tokenValue);
                if ($problem !== null) {
                    $issues[] = ['path' => "{$group}.{$name}", 'message' => $problem];

                    continue;
                }
                $out[$group][$name] = $tokenValue;
            }
        }

        return [$issues === [] ? $out : null, $issues];
    }

    /** Defaults with a parsed token set applied. */
    public static function resolve(?array $values): array
    {
        $out = self::defaults();
        foreach ($values ?? [] as $group => $names) {
            foreach ($names as $name => $value) {
                if (isset($out[$group]) && array_key_exists($name, $out[$group])) {
                    $out[$group][$name] = $value;
                }
            }
        }

        return $out;
    }

    /** The style-property-like definition a token value is validated with. */
    public static function definition(string $group, string $name): array
    {
        $definition = self::groups()[$group];

        return [
            'label' => "{$definition['label']}: {$name}",
            'kind' => $definition['kind'] === 'shadowPreset' ? 'shadow' : $definition['kind'],
            'lengths' => $definition['lengths'] ?? null,
        ];
    }

    /**
     * `:root{…}` defining exactly the token variables that `$css` uses, with the
     * resolved values. Empty when none are used.
     */
    public static function rootCss(array $resolved, string $css): string
    {
        preg_match_all('/var\(--ak-t-([A-Za-z]+)-([A-Za-z0-9]+)\)/', $css, $matches, PREG_SET_ORDER);
        $declarations = [];
        foreach ($matches as [, $group, $name]) {
            if (! isset($resolved[$group]) || ! array_key_exists($name, $resolved[$group]) || isset($declarations["{$group}-{$name}"])) {
                continue;
            }
            $value = StyleSheet::value(self::definition($group, $name), $resolved[$group][$name]);
            if ($value !== null) {
                $declarations["{$group}-{$name}"] = "--ak-t-{$group}-{$name}:{$value}";
            }
        }
        ksort($declarations);

        return $declarations === [] ? '' : ':root{'.implode(';', $declarations).'}';
    }
}

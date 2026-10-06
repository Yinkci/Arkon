<?php

namespace App\Arkon\Components;

use App\Arkon\Support\Text;

/**
 * One immutable version of a component: its manifest (props, defaults, inline
 * fields, media references, publish checks) and its stylesheet. Rendering lives
 * in a ComponentRenderer registered for the same type and version.
 */
final class ComponentDefinition
{
    public function __construct(
        public readonly string $type,
        public readonly int $version,
        public readonly string $label,
        /** @var false|array{allow: list<string>, max?: int} */
        public readonly false|array $children,
        public readonly PropSchema $props,
        public readonly array $defaultProps,
        /** @var array<string, array{kind: string}> */
        public readonly array $inlineFields,
        /** @var list<string> dotted prop paths holding media asset ids */
        public readonly array $mediaRefs,
        /** @var list<array{rule: string, prop: string, when?: string, message: string}> */
        public readonly array $publishChecks,
        public readonly string $css,
    ) {}

    public static function fromManifest(array $manifest, string $css): self
    {
        return new self(
            type: $manifest['type'],
            version: $manifest['version'],
            label: $manifest['label'],
            children: $manifest['children'] ?? false,
            props: new PropSchema($manifest['props'] ?? []),
            defaultProps: $manifest['defaultProps'] ?? [],
            inlineFields: $manifest['inlineFields'] ?? [],
            mediaRefs: $manifest['mediaRefs'] ?? [],
            publishChecks: $manifest['publishChecks'] ?? [],
            css: $css,
        );
    }

    /** @return list<string> media asset ids referenced by parsed props */
    public function mediaRefsOf(array $props): array
    {
        $ids = [];
        foreach ($this->mediaRefs as $path) {
            $value = data_get($props, $path);
            if (is_string($value)) {
                $ids[] = $value;
            }
        }

        return $ids;
    }

    /** @return list<string> problems that are fine in a draft but block publishing */
    public function publishProblems(array $props): array
    {
        $problems = [];
        foreach ($this->publishChecks as $check) {
            // `when` uses JavaScript truthiness, exactly like the editor's twin.
            if (isset($check['when']) && in_array(data_get($props, $check['when']), [null, false, '', 0, 0.0], true)) {
                continue;
            }
            $value = data_get($props, $check['prop']);
            $failed = match ($check['rule']) {
                'notBlank' => ! is_string($value) || Text::isBlank($value),
                'present' => $value === null,
                default => throw new \InvalidArgumentException("Unknown publish check {$check['rule']}"),
            };
            if ($failed) {
                $problems[] = $check['message'];
            }
        }

        return $problems;
    }
}

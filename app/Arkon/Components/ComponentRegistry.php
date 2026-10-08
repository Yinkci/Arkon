<?php

namespace App\Arkon\Components;

use App\Arkon\Components\Render\ButtonV1;
use App\Arkon\Components\Render\ButtonV2;
use App\Arkon\Components\Render\ColumnsV1;
use App\Arkon\Components\Render\ColumnsV2;
use App\Arkon\Components\Render\ColumnV1;
use App\Arkon\Components\Render\ColumnV2;
use App\Arkon\Components\Render\ComponentRenderer;
use App\Arkon\Components\Render\FragmentV1;
use App\Arkon\Components\Render\GroupV1;
use App\Arkon\Components\Render\HeroV1;
use App\Arkon\Components\Render\HeroV2;
use App\Arkon\Components\Render\HeroV3;
use App\Arkon\Components\Render\ImageV1;
use App\Arkon\Components\Render\ImageV3;
use App\Arkon\Components\Render\InstanceV1;
use App\Arkon\Components\Render\PageV1;
use App\Arkon\Components\Render\PageV3;
use App\Arkon\Components\Render\SectionV1;
use App\Arkon\Components\Render\TextV1;
use App\Arkon\Components\Render\TextV2;
use App\Arkon\Support\Json;
use App\Arkon\Themes\ThemeRenderer;
use App\Arkon\Themes\ThemeStore;
use Closure;
use RuntimeException;

/**
 * Every version of every component. Versions are immutable: a change to a
 * component's props adds `<type>/v<N+1>.json`, its renderer, and a migration
 * from N. Old versions stay registered so old revisions can still be read
 * (media references, upgrades) and migrated forward when they are restored,
 * edited or re-rendered.
 */
final class ComponentRegistry
{
    /** @var array<string, array<int, ComponentDefinition>> */
    private array $definitions = [];

    private array $themePackages = [];

    private array $themeManifests = [];

    /**
     * @param  array<string, class-string<ComponentRenderer>>  $renderers  "type@version" → renderer
     * @param  array<string, Closure(array): array>  $migrations  "type@fromVersion" → props of version+1
     */
    public function __construct(string $directory, private readonly array $renderers, private readonly array $migrations = [], array $themePackages = [])
    {
        foreach (glob($directory.'/*/v*.json') ?: [] as $file) {
            $manifest = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $cssFile = substr($file, 0, -5).'.css';
            $css = is_file($cssFile) ? (string) file_get_contents($cssFile) : '';
            $definition = ComponentDefinition::fromManifest($manifest, $css);
            $this->definitions[$definition->type][$definition->version] = $definition;
        }
        foreach ($themePackages as $package) {
            $manifest = $package['manifest'];
            $definition = ComponentDefinition::fromManifest($manifest, $package['css']);
            $this->definitions[$definition->type][$definition->version] = $definition;
            $this->themePackages[$definition->type.'@'.$definition->version] = $package;
            $manifest['defaultProps'] = $definition->defaultProps;
            $this->themeManifests[] = $manifest;
        }
        // A registration policy extension, not an edit to any released manifest or stylesheet.
        $themeTypes = array_unique(array_column($this->themeManifests, 'type'));
        foreach ($this->definitions as $type => $versions) {
            foreach ($versions as $version => $definition) {
                if ($definition->children !== false && in_array('text', $definition->children['allow'], true)) {
                    $children = $definition->children;
                    $children['allow'] = array_values(array_unique([...$children['allow'], ...$themeTypes]));
                    $this->definitions[$type][$version] = new ComponentDefinition($definition->type, $definition->version, $definition->label, $children, $definition->props, $definition->defaultProps, $definition->inlineFields, $definition->mediaRefs, $definition->publishChecks, $definition->css);
                }
            }
        }
        foreach ($this->definitions as $type => $versions) {
            ksort($this->definitions[$type]);
        }
    }

    /**
     * Renderers of every released component version ("type@version"). Never
     * remove an entry: publications made with it must stay reproducible.
     */
    public const RENDERERS = [
        'page@1' => PageV1::class,
        // v2 only widens which sections a page may contain; its markup is unchanged.
        'page@2' => PageV1::class,
        'hero@1' => HeroV1::class,
        'text@1' => TextV1::class,
        'image@1' => ImageV1::class,
        // v2 keeps the markup; only its stylesheet changes (sizes apply inside columns too).
        'image@2' => ImageV1::class,
        'button@1' => ButtonV1::class,
        'columns@1' => ColumnsV1::class,
        'column@1' => ColumnV1::class,
        // The shared styling model (style props), sections, groups and reusable components.
        'page@3' => PageV3::class,
        'section@1' => SectionV1::class,
        'group@1' => GroupV1::class,
        'hero@2' => HeroV2::class,
        'text@2' => TextV2::class,
        'image@3' => ImageV3::class,
        'button@2' => ButtonV2::class,
        'columns@2' => ColumnsV2::class,
        'column@2' => ColumnV2::class,
        'instance@1' => InstanceV1::class,
        'fragment@1' => FragmentV1::class,
        // A set width of the content area or image wins over the equal flex share.
        'hero@3' => HeroV3::class,
        // Entrance animations: the root slot accepts the "motion" design settings. The markup is
        // unchanged; PageRenderer adds the animation classes (and suppresses them where needed).
        'section@2' => SectionV1::class,
        'group@2' => GroupV1::class,
        'hero@4' => HeroV3::class,
        'text@3' => TextV2::class,
        'image@4' => ImageV3::class,
        'button@3' => ButtonV2::class,
        'columns@3' => ColumnsV2::class,
        'column@3' => ColumnV2::class,
        'instance@2' => InstanceV1::class,
    ];

    /** @return array<string, Closure(array): array> "type@fromVersion" → props of version+1 */
    public static function migrations(): array
    {
        // Props arrive in raw form (nested objects may be stdClass). Styles produced here never contain
        // empty objects, so they stay objects when encoded.
        return [
            'page@1' => fn (array $props) => $props,
            'image@1' => fn (array $props) => $props,
            'page@2' => fn (array $props) => $props,
            'hero@1' => fn (array $props) => $props,
            'hero@2' => fn (array $props) => $props,
            'text@1' => function (array $props) {
                $align = $props['align'] ?? 'start';
                unset($props['align']);

                return $align === 'center' ? [...$props, 'style' => ['root' => ['base' => ['textAlign' => 'center']]]] : $props;
            },
            'image@2' => function (array $props) {
                $size = $props['size'] ?? 'full';
                unset($props['size']);
                $width = ['medium' => '48rem', 'small' => '28rem'][$size] ?? null;

                return $width ? [...$props, 'style' => ['root' => ['base' => ['maxWidth' => $width]]]] : $props;
            },
            'button@1' => function (array $props) {
                $variant = $props['style'] ?? 'primary';
                unset($props['style']);

                return [...$props, 'variant' => $variant];
            },
            'columns@1' => function (array $props) {
                $stackOn = $props['stackOn'] ?? 'mobile';
                $gap = ['small' => '@space.md', 'large' => '@space.xl'][$props['gap'] ?? 'medium'] ?? null;
                $root = [$stackOn => ['columns' => '1']];
                if ($gap) {
                    $root = ['base' => ['gap' => $gap], ...$root];
                }

                return ['style' => ['root' => $root]];
            },
            'column@1' => fn (array $props) => $props,
            // Animation settings became available: same props, nothing to convert.
            'section@1' => fn (array $props) => $props,
            'group@1' => fn (array $props) => $props,
            'hero@3' => fn (array $props) => $props,
            'text@2' => fn (array $props) => $props,
            'image@3' => fn (array $props) => $props,
            'button@2' => fn (array $props) => $props,
            'columns@2' => fn (array $props) => $props,
            'column@2' => fn (array $props) => $props,
            'instance@1' => fn (array $props) => $props,
        ];
    }

    /** Whether a component version holds children (a container). */
    private function gainsChildren(string $type, int $to): bool
    {
        $definition = $this->get($type, $to);

        return $definition !== null && $definition->children !== false;
    }

    public static function default(): self
    {
        $packages = ThemeStore::installed();
        $migrations = self::migrations();
        foreach ($packages as $p) {
            if ($p['manifest']['version'] > 1) {
                $migrations[$p['manifest']['type'].'@'.($p['manifest']['version'] - 1)] = fn (array $props) => $props;
            }
        }

        return new self(resource_path('arkon/components'), self::RENDERERS, $migrations, $packages);
    }

    public function themeManifests(): array
    {
        return $this->themeManifests;
    }

    public function get(string $type, int $version): ?ComponentDefinition
    {
        return $this->definitions[$type][$version] ?? null;
    }

    public function current(string $type): ?ComponentDefinition
    {
        $versions = $this->definitions[$type] ?? [];

        return $versions === [] ? null : end($versions);
    }

    /** @return array<string, ComponentDefinition> type → its current definition */
    public function currentDefinitions(): array
    {
        return array_map(fn (array $versions) => end($versions), $this->definitions);
    }

    /** @return array<string, int> type → current version */
    public function currentVersions(): array
    {
        return array_map(fn (array $versions) => array_key_last($versions), $this->definitions);
    }

    public function renderer(string $type, int $version): ComponentRenderer
    {
        if (isset($this->themePackages["{$type}@{$version}"])) {
            return new ThemeRenderer($this->themePackages["{$type}@{$version}"]);
        }
        $class = $this->renderers["{$type}@{$version}"] ?? throw new RuntimeException("No renderer for {$type} v{$version}");

        return new $class;
    }

    /**
     * Brings every node to its component's current version, in memory. Nodes of
     * unknown types or without a migration path are left as they are (and then
     * fail validation with a clear message instead of being guessed at).
     */
    public function migrateDocument(mixed $doc): mixed
    {
        if (! is_array($doc) || ! Json::isObject($doc['nodes'] ?? null)) {
            return $doc;
        }
        foreach (Json::entries($doc['nodes']) as $id => $node) {
            if (! is_array($node) || ! is_string($node['type'] ?? null) || ! is_int($node['version'] ?? null)) {
                continue;
            }
            $current = $this->current($node['type']);
            while ($current && $node['version'] < $current->version) {
                $migrate = $this->migrations["{$node['type']}@{$node['version']}"] ?? null;
                if ($migrate === null) {
                    break;
                }
                $props = $migrate(Json::entries($node['props'] ?? []));
                $node['props'] = $props === [] ? new \stdClass : $props;
                $node['version']++;
                // A component that became a container (hero v2 holds buttons) starts with no children.
                if (! array_key_exists('children', $node) && $this->gainsChildren($node['type'], $node['version'])) {
                    $node['children'] = [];
                }
            }
            $doc['nodes'][$id] = $node;
        }

        return $doc;
    }

    /** @return array<string, list<string>> type → inline fields that keep line breaks (current versions) */
    public function multilineFields(): array
    {
        $out = [];
        foreach (array_keys($this->definitions) as $type) {
            $fields = $this->current($type)->inlineFields;
            $out[$type] = array_keys(array_filter($fields, fn ($field) => ($field['kind'] ?? '') === 'multiline'));
        }

        return $out;
    }
}

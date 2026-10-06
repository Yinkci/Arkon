<?php

namespace App\Arkon\Components;

use App\Arkon\Components\Render\ButtonV1;
use App\Arkon\Components\Render\ColumnsV1;
use App\Arkon\Components\Render\ColumnV1;
use App\Arkon\Components\Render\ComponentRenderer;
use App\Arkon\Components\Render\HeroV1;
use App\Arkon\Components\Render\ImageV1;
use App\Arkon\Components\Render\PageV1;
use App\Arkon\Components\Render\TextV1;
use App\Arkon\Support\Json;
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

    /**
     * @param  array<string, class-string<ComponentRenderer>>  $renderers  "type@version" → renderer
     * @param  array<string, Closure(array): array>  $migrations  "type@fromVersion" → props of version+1
     */
    public function __construct(string $directory, private readonly array $renderers, private readonly array $migrations = [])
    {
        foreach (glob($directory.'/*/v*.json') ?: [] as $file) {
            $manifest = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $cssFile = substr($file, 0, -5).'.css';
            $css = is_file($cssFile) ? (string) file_get_contents($cssFile) : '';
            $definition = ComponentDefinition::fromManifest($manifest, $css);
            $this->definitions[$definition->type][$definition->version] = $definition;
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
    ];

    /** @return array<string, Closure(array): array> "type@fromVersion" → props of version+1 */
    public static function migrations(): array
    {
        return [
            'page@1' => fn (array $props) => $props,
            'image@1' => fn (array $props) => $props,
        ];
    }

    public static function default(): self
    {
        return new self(resource_path('arkon/components'), self::RENDERERS, self::migrations());
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

    /** @return array<string, int> type → current version */
    public function currentVersions(): array
    {
        return array_map(fn (array $versions) => array_key_last($versions), $this->definitions);
    }

    public function renderer(string $type, int $version): ComponentRenderer
    {
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

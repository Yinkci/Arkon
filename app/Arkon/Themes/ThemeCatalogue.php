<?php

namespace App\Arkon\Themes;

use App\Arkon\Components\ComponentRegistry;
use App\Arkon\Components\DocumentValidator;
use App\Arkon\Components\Factories;
use App\Arkon\Renderer\PageRenderer;
use App\Arkon\Schema\Operations;
use RuntimeException;

final class ThemeCatalogue
{
    public static function root(): string
    {
        return (string) config('arkon.theme_source', base_path('themes'));
    }

    public static function source(string $id): string
    {
        if (preg_match('/^[a-z][a-z0-9-]{0,24}$/D', $id) !== 1) {
            throw new RuntimeException('Invalid theme identifier.');
        }
        $root = realpath(self::root());
        $dir = realpath(self::root().'/'.$id);
        if ($root === false || $dir === false || ! str_starts_with(strtolower($dir), strtolower($root.DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Theme is not available inside themes/.');
        }

        return $dir;
    }

    public static function candidate(string $id): array
    {
        $dir = self::source($id);
        $packages = ThemeStore::validate($dir);
        $meta = json_decode(file_get_contents($dir.'/theme.json'), true, 512, JSON_THROW_ON_ERROR);
        if ($meta['id'] !== $id || ! is_string($meta['name'] ?? $id) || strlen($meta['name'] ?? $id) > 80 || ! is_string($meta['description'] ?? '') || strlen($meta['description'] ?? '') > 300 || ! is_int($meta['version'] ?? 1) || ($meta['version'] ?? 1) < 1) {
            throw new RuntimeException('Theme metadata needs a matching folder/id, a short name/description and positive integer version.');
        }

        return ['id' => $id, 'name' => $meta['name'] ?? ucfirst($id), 'description' => $meta['description'] ?? '', 'version' => $meta['version'] ?? 1,
            'types' => array_column(array_column($packages, 'manifest'), 'type'), 'packages' => $packages];
    }

    public static function listing(): array
    {
        $rows = [];
        foreach (glob(self::root().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            try {
                $candidate = self::candidate($id);
                unset($candidate['packages']);
                $rows[] = [...$candidate, 'error' => null];
            } catch (\Throwable $e) {
                $rows[] = ['id' => $id, 'name' => $id, 'description' => '', 'version' => null, 'types' => [], 'error' => $e->getMessage()];
            }
        }

        return $rows;
    }

    public static function preview(string $id): string
    {
        $candidate = self::candidate($id);
        $packages = [];
        foreach ([...ThemeStore::installed(), ...$candidate['packages']] as $p) {
            $packages[$p['manifest']['type'].'@'.$p['manifest']['version']] = $p;
        }
        $registry = new ComponentRegistry(resource_path('arkon/components'), ComponentRegistry::RENDERERS, ComponentRegistry::migrations(), array_values($packages));
        $renderer = new PageRenderer($registry, new DocumentValidator($registry));
        $nodes = [Factories::heroNode(['heading' => $candidate['name'], 'text' => 'Component preview. Your pages have not changed.'])];
        foreach ($candidate['types'] as $type) {
            $d = $registry->current($type);
            $nodes[] = ['id' => Operations::newNodeId(), 'type' => $type, 'version' => $d->version, 'props' => $d->defaultProps];
        }

        return $renderer->render(Factories::pageDocument($nodes), 'production', ['title' => $candidate['name'], 'path' => '/'], ['name' => 'Theme preview'], [], resources: ['tokens' => ['version' => null, 'values' => []]])['html'];
    }
}

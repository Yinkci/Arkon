<?php

namespace App\Arkon\Themes;

use App\Arkon\Support\Json;
use RuntimeException;

/** Local developer packages, installed as immutable snapshots; never execute theme code. */
final class ThemeStore
{
    public static function directory(): string
    {
        $directory = (string) config('arkon.theme_store', storage_path('app/theme-components'));

        $directory = str_replace(chr(92), '/', $directory);

        return preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $directory) ? $directory : base_path($directory);
    }

    public static function installed(): array
    {
        $packages = [];
        foreach (glob(self::directory().'/*/v*.json') ?: [] as $file) {
            $p = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (($p['format'] ?? null) !== 1 || ! isset($p['manifest'], $p['template'], $p['css'], $p['hash'])) {
                throw new RuntimeException('Invalid installed theme snapshot: '.$file);
            }
            $hash = $p['hash'];
            unset($p['hash']);
            if (! hash_equals($hash, hash('sha256', Json::encode($p)))) {
                throw new RuntimeException('Theme snapshot changed: '.$file);
            }
            $packages[] = [...$p, 'hash' => $hash];
        }

        return $packages;
    }

    public static function validate(string $directory): array
    {
        $root = realpath($directory);
        if ($root === false || ! is_dir($root)) {
            throw new RuntimeException('Theme folder does not exist.');
        }
        $read = function (string $relative) use ($root): string {
            $file = realpath($root.DIRECTORY_SEPARATOR.$relative);
            if ($file === false || ! str_starts_with(strtolower($file), strtolower($root.DIRECTORY_SEPARATOR)) || ! is_file($file) || filesize($file) > 65536) {
                throw new RuntimeException('Missing, oversized or outside-theme file: '.$relative);
            }

            return file_get_contents($file);
        };
        $theme = json_decode($read('theme.json'), true, 512, JSON_THROW_ON_ERROR);
        if (($theme['format'] ?? null) !== 1 || preg_match('/^[a-z][a-z0-9-]{0,24}$/D', $theme['id'] ?? '') !== 1) {
            throw new RuntimeException('theme.json needs format 1 and a lowercase theme id.');
        }
        if (isset($theme['parent'])) {
            throw new RuntimeException('Theme inheritance is not implemented in this first milestone.');
        }
        $entries = $theme['components'] ?? [];
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) < 1 || count($entries) > 30) {
            throw new RuntimeException('List 1–30 component folders in theme.json.');
        }
        $out = [];
        foreach ($entries as $name) {
            if (! is_string($name) || preg_match('/^[a-z][a-z0-9-]{0,24}$/D', $name) !== 1) {
                throw new RuntimeException('Invalid component folder name.');
            }
            $manifest = json_decode($read("components/{$name}/component.json"), true, 512, JSON_THROW_ON_ERROR);
            $type = 'theme-'.$theme['id'].'-'.$name;
            if (($manifest['type'] ?? null) !== $type || ! is_int($manifest['version'] ?? null) || $manifest['version'] < 1 || $manifest['version'] > 1000) {
                throw new RuntimeException("{$name}: type must be {$type}, with an integer version from 1 to 1000.");
            }
            Template::validateManifest($manifest);
            $template = Template::compile($read("components/{$name}/template.html"), $manifest);
            $css = Template::css($read("components/{$name}/styles.css"), $manifest);
            $p = ['format' => 1, 'manifest' => $manifest, 'template' => $template, 'css' => $css];
            $out[] = [...$p, 'hash' => hash('sha256', Json::encode($p))];
        }
        if (count(array_unique(array_column(array_column($out, 'manifest'), 'type'))) !== count($out)) {
            throw new RuntimeException('Duplicate component folders.');
        }

        return $out;
    }

    public static function install(string $directory): array
    {
        $packages = self::validate($directory);
        $installed = self::installed();
        // Preflight the entire package before writing. Installing never modifies an existing version.
        foreach ($packages as $p) {
            $m = $p['manifest'];
            $versions = array_values(array_filter($installed, fn ($old) => $old['manifest']['type'] === $m['type']));
            $previous = null;
            foreach ($versions as $old) {
                if ($old['manifest']['version'] === $m['version'] && $old['hash'] !== $p['hash']) {
                    throw new RuntimeException("{$m['type']} v{$m['version']} is immutable. Increase the version.");
                }
                if ($old['manifest']['version'] === $m['version'] - 1) {
                    $previous = $old['manifest'];
                }
            }
            if ($m['version'] > 1 && $previous === null) {
                throw new RuntimeException('Install the preceding component version first.');
            }
            if ($previous !== null) {
                foreach (['props', 'inlineFields', 'mediaRefs', 'publishChecks'] as $key) {
                    if (($m[$key] ?? []) != ($previous[$key] ?? [])) {
                        throw new RuntimeException('This milestone supports presentation updates only; field/schema changes need a new component type.');
                    }
                }
            }
        }
        foreach ($packages as $p) {
            $dir = self::directory().'/'.$p['manifest']['type'];
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException('Cannot create theme snapshot directory.');
            }
            $file = $dir.'/v'.$p['manifest']['version'].'.json';
            // Publish a complete file atomically. A lock prevents concurrent version replacement.
            $lock = fopen($dir.'/.install.lock', 'c');
            if (! $lock || ! flock($lock, LOCK_EX)) {
                throw new RuntimeException('Cannot lock theme installation.');
            }
            try {
                if (is_file($file)) {
                    $old = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                    if (($old['hash'] ?? null) !== $p['hash']) {
                        throw new RuntimeException('Concurrent install used different component bytes.');
                    }
                } else {
                    $temp = tempnam($dir, '.package-');
                    try {
                        if (file_put_contents($temp, Json::encode($p)) === false || ! rename($temp, $file)) {
                            throw new RuntimeException('Cannot save theme snapshot.');
                        }
                    } finally {
                        if (is_file($temp)) {
                            unlink($temp);
                        }
                    }
                }
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        return $packages;
    }
}

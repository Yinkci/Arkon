<?php

namespace App\Arkon\Media;

use RuntimeException;

/** Local media storage. Keys are server-generated (`<uuid>.<ext>`); anything else is refused. */
class MediaStorage
{
    public const KEY_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\.(jpg|png|gif|webp|avif)$/D';

    public function __construct(public readonly string $root) {}

    public static function fromConfig(): self
    {
        $root = (string) config('arkon.media_root');
        $absolute = preg_match('#^([A-Za-z]:[\\\\/]|/)#', $root) === 1 ? $root : base_path($root);

        return new self(rtrim($absolute, '\\/'));
    }

    public static function isValidKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    public function put(string $key, string $data): void
    {
        if (! self::isValidKey($key)) {
            throw new RuntimeException('Invalid storage key');
        }
        if (! is_dir($this->root) && ! mkdir($this->root, 0775, true) && ! is_dir($this->root)) {
            throw new RuntimeException('Media directory could not be created');
        }
        // "x": never overwrite. Assets are immutable.
        $handle = @fopen($this->root.DIRECTORY_SEPARATOR.$key, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Media file could not be written');
        }
        fwrite($handle, $data);
        fclose($handle);
    }

    public function path(string $key): ?string
    {
        if (! self::isValidKey($key)) {
            return null;
        }
        $path = $this->root.DIRECTORY_SEPARATOR.$key;

        return is_file($path) ? $path : null;
    }

    public function read(string $key): ?string
    {
        $path = $this->path($key);

        return $path === null ? null : (string) file_get_contents($path);
    }
}

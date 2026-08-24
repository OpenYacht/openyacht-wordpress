<?php

declare(strict_types=1);

namespace OpenYacht\Media;

use RuntimeException;

/**
 * Default driver: uploads/openyacht/ under the WordPress uploads dir.
 */
final class LocalStorage implements Storage
{
    private const BASE = 'openyacht';

    public function write(string $path, string $bytes): string
    {
        $path = $this->sanitize($path);
        $absolute = $this->absolute($path);
        $directory = dirname($absolute);

        if (! wp_mkdir_p($directory)) {
            throw new RuntimeException("Could not create media directory {$directory}.");
        }

        $silence = dirname($directory) . '/index.php';

        if (! file_exists($silence)) {
            @file_put_contents($silence, "<?php // Silence is golden.\n");
        }

        if (file_put_contents($absolute, $bytes) === false) {
            throw new RuntimeException("Could not write media file {$absolute}.");
        }

        return $path;
    }

    public function read(string $path): string
    {
        $bytes = @file_get_contents($this->absolute($this->sanitize($path)));

        if ($bytes === false) {
            throw new RuntimeException("Could not read media file {$path}.");
        }

        return $bytes;
    }

    public function url(string $path): string
    {
        $uploads = wp_upload_dir();

        return rtrim((string) $uploads['baseurl'], '/') . '/' . self::BASE . '/' . $this->sanitize($path);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->absolute($this->sanitize($path)));
    }

    public function delete(string $path): bool
    {
        $absolute = $this->absolute($this->sanitize($path));

        return ! file_exists($absolute) || unlink($absolute);
    }

    public function deleteDirectory(string $directory): bool
    {
        $absolute = $this->absolute($this->sanitize($directory));

        if (! is_dir($absolute)) {
            return true;
        }

        foreach ((array) scandir($absolute) as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_string($entry)) {
                continue;
            }

            $child = $absolute . '/' . $entry;
            is_dir($child) ? $this->deleteDirectory($this->sanitize($directory) . '/' . $entry) : @unlink($child);
        }

        return rmdir($absolute);
    }

    private function absolute(string $path): string
    {
        $uploads = wp_upload_dir();

        return rtrim((string) $uploads['basedir'], '/') . '/' . self::BASE . '/' . $path;
    }

    /**
     * Store-relative paths only: no traversal, no absolute paths.
     */
    private function sanitize(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, '..')) {
            throw new RuntimeException("Invalid media path [{$path}].");
        }

        return $path;
    }
}

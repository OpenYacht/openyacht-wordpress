<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Media\Storage;

final class FakeStorage implements Storage
{
    /** @var array<string, string> path => bytes */
    public array $files = [];

    /** @var list<string> */
    public array $deletedDirectories = [];

    public function write(string $path, string $bytes): string
    {
        $this->files[$path] = $bytes;

        return $path;
    }

    public function url(string $path): string
    {
        return 'https://node.test/wp-content/uploads/openyacht/' . $path;
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function delete(string $path): bool
    {
        unset($this->files[$path]);

        return true;
    }

    public function deleteDirectory(string $directory): bool
    {
        $this->deletedDirectories[] = $directory;

        foreach (array_keys($this->files) as $path) {
            if (str_starts_with($path, $directory . '/')) {
                unset($this->files[$path]);
            }
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Media;

use OpenYacht\Federation\CopyMediaRepository;
use OpenYacht\Federation\CopyRepository;
use Throwable;

/**
 * Moves every cached media file from one storage driver to another. One
 * implementation shared by `wp openyacht media migrate` and the storage
 * add-ons' admin surfaces. The database stores only store-relative paths
 * and URLs are computed from the active driver, so no rows change: copy
 * the files, then switch the driver.
 *
 * Resumable by design — files already present on the target are skipped,
 * and per-file failures are collected, never fatal.
 */
final class Migrator
{
    public function __construct(
        private readonly CopyRepository $copies,
        private readonly CopyMediaRepository $media,
    ) {
    }

    /**
     * @return array{copied: int, skipped: int, failures: array<string, string>}
     */
    public function migrate(Storage $from, Storage $to, bool $dryRun = false): array
    {
        $copied = 0;
        $skipped = 0;
        $failures = [];

        foreach ($this->eachPath() as $path) {
            if ($to->exists($path)) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $copied++;
                continue;
            }

            try {
                $to->write($path, $from->read($path));
                $copied++;
            } catch (Throwable $exception) {
                $failures[$path] = $exception->getMessage();
            }
        }

        return ['copied' => $copied, 'skipped' => $skipped, 'failures' => $failures];
    }

    /**
     * Delete source files whose copy verifiably exists on the target, then
     * sweep each copy's directory. Only call after a fully clean migrate.
     */
    public function deleteSource(Storage $from, Storage $to): int
    {
        $deleted = 0;

        foreach ($this->copies->active() as $copy) {
            $clean = true;

            foreach ($this->media->forCopy($copy->id) as $item) {
                foreach ($item->renditions as $rendition) {
                    if ($to->exists($rendition['path'])) {
                        if ($from->delete($rendition['path'])) {
                            $deleted++;
                        }
                    } elseif ($from->exists($rendition['path'])) {
                        // Not on the target — keep the source copy, and keep
                        // the directory so the sweep can't take it either.
                        $clean = false;
                    }
                }
            }

            if ($clean) {
                $from->deleteDirectory((string) $copy->id);
            }
        }

        return $deleted;
    }

    /**
     * @return \Generator<string>
     */
    private function eachPath(): \Generator
    {
        foreach ($this->copies->active() as $copy) {
            foreach ($this->media->forCopy($copy->id) as $item) {
                foreach ($item->renditions as $rendition) {
                    yield $rendition['path'];
                }
            }
        }
    }
}

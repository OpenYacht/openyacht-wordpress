<?php

declare(strict_types=1);

namespace OpenYacht\Media;

/**
 * Storage boundary for cached partner media. Synced images are cache, not
 * content: the plugin must refresh, replace, and delete them unilaterally
 * to meet copy-fidelity and freshness obligations, so they live in a
 * plugin-owned store — never the media library, never attachments.
 *
 * Paths are store-relative ('{copy-id}/{file}'). write() returns the path
 * actually written. Additional drivers (S3 etc.) register via the
 * openyacht_storage_drivers filter and ship as separate plugins.
 */
interface Storage
{
    /**
     * @return string the store-relative path written
     */
    public function write(string $path, string $bytes): string;

    /**
     * The stored bytes; throws when the file is absent. Exists so one
     * driver can be migrated into another (`wp openyacht media migrate`).
     */
    public function read(string $path): string;

    public function url(string $path): string;

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    public function deleteDirectory(string $directory): bool;
}

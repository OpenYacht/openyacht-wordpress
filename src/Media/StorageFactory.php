<?php

declare(strict_types=1);

namespace OpenYacht\Media;

use RuntimeException;

/**
 * Driver selection. Only the local driver ships in core; other drivers
 * (S3/R2 — which would drag in vendor SDKs) register via the filter from
 * their own plugin. Unknown or broken drivers fail loudly — never a
 * silent fallback to local.
 */
final class StorageFactory
{
    public static function make(): Storage
    {
        $storage = apply_filters('openyacht_storage_driver', new LocalStorage());

        if (! $storage instanceof Storage) {
            throw new RuntimeException('openyacht_storage_driver must return an OpenYacht\Media\Storage implementation.');
        }

        return $storage;
    }
}

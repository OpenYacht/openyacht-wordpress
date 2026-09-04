<?php

declare(strict_types=1);

namespace OpenYacht\Media;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Admin\Settings;
use RuntimeException;

/**
 * Driver registry + selection. Only the local driver ships in core; other
 * drivers (S3/R2 — which would drag in vendor SDKs) register from their
 * own plugin via the openyacht_storage_drivers filter and become
 * selectable in the OpenYacht settings. A configured driver that is not
 * registered fails loudly — never a silent fallback to local.
 */
final class StorageFactory
{
    /**
     * @return array<string, array{label: string, factory: callable(): Storage}>
     */
    public static function drivers(): array
    {
        $drivers = [
            'local' => [
                'label' => __('Local uploads folder', 'openyacht'),
                'factory' => static fn (): Storage => new LocalStorage(),
            ],
        ];

        $drivers = apply_filters('openyacht_storage_drivers', $drivers);

        return is_array($drivers) ? $drivers : [];
    }

    public static function make(): Storage
    {
        $name = Settings::get('storage_driver');

        return self::makeFor(is_string($name) && $name !== '' ? $name : 'local');
    }

    /**
     * A specific registered driver regardless of which one is configured —
     * the migration command needs a source and a target side by side.
     */
    public static function makeFor(string $name): Storage
    {
        $drivers = self::drivers();

        if (! isset($drivers[$name]['factory'])) {
            throw new RuntimeException("Storage driver [{$name}] is selected but not registered. Activate the plugin providing it or switch back to local in OpenYacht settings.");
        }

        $storage = ($drivers[$name]['factory'])();

        if (! $storage instanceof Storage) {
            throw new RuntimeException("Storage driver [{$name}] did not produce an OpenYacht\\Media\\Storage implementation.");
        }

        return $storage;
    }
}

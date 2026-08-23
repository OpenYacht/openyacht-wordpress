<?php

declare(strict_types=1);

namespace OpenYacht\Cli;

use OpenYacht\Services;
use Throwable;
use WP_CLI;

/**
 * Cached-media management: `wp openyacht media sync`.
 */
final class MediaCommand
{
    /**
     * Fetch and cache media for synced copies whose wire media set changed.
     *
     * ## OPTIONS
     *
     * [--copy-id=<id>]
     * : Sync media for one copy only.
     *
     * [--force]
     * : Re-fetch even when the stored set matches the wire set.
     *
     * ## EXAMPLES
     *
     *     wp openyacht media sync
     *
     * @param array<int, string> $args
     * @param array<string, string|bool> $assocArgs
     */
    public function sync(array $args, array $assocArgs): void
    {
        $media = Services::mediaService();
        $copies = isset($assocArgs['copy-id'])
            ? array_filter([Services::copies()->find((int) $assocArgs['copy-id'])])
            : Services::copies()->active();
        $force = (bool) ($assocArgs['force'] ?? false);
        $synced = 0;

        foreach ($copies as $copy) {
            if (! $force && ! $media->needsSync($copy)) {
                continue;
            }

            if ($force) {
                $media->expire($copy);
            }

            try {
                $count = $media->sync($copy);
                $synced++;
                WP_CLI::line("{$copy->canonicalUri}: cached {$count} image(s)");
            } catch (Throwable $exception) {
                WP_CLI::warning("{$copy->canonicalUri}: {$exception->getMessage()}");
            }
        }

        WP_CLI::success("Media synced for {$synced} cop" . ($synced === 1 ? 'y' : 'ies') . '.');
    }
}

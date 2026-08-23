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
     * Respects the media policy (selected-only by default).
     *
     * ## OPTIONS
     *
     * [--copy-id=<id>]
     * : Sync media for one copy only (ignores the policy).
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
            : array_filter(Services::copies()->active(), \OpenYacht\Media\MediaPolicy::shouldCache(...));
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

    /**
     * Delete cached media for copies the media policy no longer covers
     * (e.g. after switching to selected-only caching).
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Report what would be purged without deleting anything.
     *
     * ## EXAMPLES
     *
     *     wp openyacht media purge --dry-run
     *
     * @param array<int, string> $args
     * @param array<string, string|bool> $assocArgs
     */
    public function purge(array $args, array $assocArgs): void
    {
        $dryRun = (bool) ($assocArgs['dry-run'] ?? false);
        $media = Services::mediaService();
        $purged = 0;

        foreach (Services::copies()->active() as $copy) {
            if (\OpenYacht\Media\MediaPolicy::shouldCache($copy)) {
                continue;
            }

            $cached = count(Services::copyMedia()->forCopy($copy->id));

            if ($cached === 0) {
                continue;
            }

            if ($dryRun) {
                WP_CLI::line("would purge {$cached} image(s): {$copy->canonicalUri}");
            } else {
                $media->expire($copy);
            }

            $purged++;
        }

        WP_CLI::success(($dryRun ? 'Would purge' : 'Purged') . " cached media for {$purged} cop" . ($purged === 1 ? 'y' : 'ies') . '.');
    }
}

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

    /**
     * Copy every cached media file from one storage driver to another —
     * either direction between any two registered drivers (local to R2,
     * R2 back to local, one provider to the next). The database stores
     * only store-relative paths and URLs are computed from the active
     * driver, so no rows change: copy the files, then switch the driver.
     *
     * ## OPTIONS
     *
     * --to=<driver>
     * : Target storage driver (see the Media storage setting for names, e.g. local, r2).
     *
     * [--from=<driver>]
     * : Source driver. Defaults to the currently configured one.
     *
     * [--switch]
     * : On a fully clean run, point the Media storage setting at the target.
     *
     * [--delete-source]
     * : On a fully clean run, delete the files from the source driver.
     *
     * [--dry-run]
     * : Report what would be copied without writing anything.
     *
     * ## EXAMPLES
     *
     *     wp openyacht media migrate --to=r2 --dry-run
     *     wp openyacht media migrate --to=r2 --switch
     *     wp openyacht media migrate --from=r2 --to=local --switch --delete-source
     *
     * @param array<int, string> $args
     * @param array<string, string|bool> $assocArgs
     */
    public function migrate(array $args, array $assocArgs): void
    {
        $configured = \OpenYacht\Admin\Settings::get('storage_driver');
        $configured = is_string($configured) && $configured !== '' ? $configured : 'local';
        $fromName = is_string($assocArgs['from'] ?? null) ? (string) $assocArgs['from'] : $configured;
        $toName = is_string($assocArgs['to'] ?? null) ? (string) $assocArgs['to'] : '';
        $dryRun = (bool) ($assocArgs['dry-run'] ?? false);

        if ($toName === '' || $toName === $fromName) {
            WP_CLI::error('Pass --to=<driver> with a driver different from the source.');

            return;
        }

        try {
            $from = \OpenYacht\Media\StorageFactory::makeFor($fromName);
            $to = \OpenYacht\Media\StorageFactory::makeFor($toName);
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());

            return;
        }

        $migrator = new \OpenYacht\Media\Migrator(Services::copies(), Services::copyMedia());
        $result = $migrator->migrate($from, $to, $dryRun);

        foreach ($result['failures'] as $path => $message) {
            WP_CLI::warning("{$path}: {$message}");
        }

        $verb = $dryRun ? 'Would copy' : 'Copied';
        WP_CLI::line("{$verb} {$result['copied']} file(s) {$fromName} → {$toName}; {$result['skipped']} already present; " . count($result['failures']) . ' failed.');

        if ($dryRun) {
            return;
        }

        if ($result['failures'] !== []) {
            WP_CLI::error('Migration incomplete — nothing was switched or deleted. Re-run to retry the failed files (already-copied ones are skipped).');

            return;
        }

        if ($configured === $toName) {
            WP_CLI::line("Media storage already serves from {$toName}.");
        } elseif ((bool) ($assocArgs['switch'] ?? false)) {
            $settings = get_option(\OpenYacht\Admin\Settings::OPTION, []);
            $settings = is_array($settings) ? $settings : [];
            $settings['storage_driver'] = $toName;
            update_option(\OpenYacht\Admin\Settings::OPTION, $settings, false);
            WP_CLI::line("Media storage switched to {$toName}.");
        } else {
            WP_CLI::line("Files are in place. Switch the Media storage setting to {$toName} (or re-run with --switch) to start serving from it.");
        }

        if ((bool) ($assocArgs['delete-source'] ?? false)) {
            WP_CLI::line('Deleted ' . $migrator->deleteSource($from, $to) . " file(s) from {$fromName}.");
        }

        WP_CLI::success('Migration complete.');
    }
}

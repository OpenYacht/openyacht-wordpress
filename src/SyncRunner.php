<?php

declare(strict_types=1);

namespace OpenYacht;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\Partner;
use OpenYacht\Federation\SyncResult;
use Throwable;

/**
 * Runs the sync pass over every due partner. Scheduled hourly on wp-cron
 * so copies stay inside the 24-hour freshness obligation (ID-7) with
 * margin, and invoked directly by `wp openyacht sync`.
 *
 * Because wp-cron is visit-triggered, a low-traffic site can sleep past
 * the obligation — the watchdog surfaces that as an admin notice steering
 * toward system cron. A conformance matter, not a nicety.
 */
final class SyncRunner
{
    public const HOOK = 'openyacht_sync';

    public const LAST_RUN_OPTION = 'openyacht_last_sync_run';

    /**
     * Watchdog threshold: half the 24-hour obligation.
     */
    public const STALE_RUN_SECONDS = 12 * 3600;

    /**
     * @param callable(string, ?Partner, ?SyncResult, ?Throwable): void|null $report
     * @return bool true when every due partner synced cleanly
     */
    public static function run(?callable $report = null, ?string $onlyDomain = null, bool $force = false): bool
    {
        $partners = Services::partners()->syncable();
        $sync = Services::syncService();
        $allOk = true;

        foreach ($partners as $partner) {
            if ($onlyDomain !== null && $partner->domain !== $onlyDomain) {
                continue;
            }

            if (! $force && ! $sync->isDue($partner)) {
                if ($report !== null) {
                    $report('skipped (backoff)', $partner, null, null);
                }

                continue;
            }

            try {
                $result = $sync->sync($partner);
                Services::logger()->log(
                    'sync',
                    "Synced {$partner->domain}: {$result->created} created, {$result->updated} updated, {$result->tombstoned} tombstoned",
                    'ok',
                    $partner->id,
                );

                if ($report !== null) {
                    $report('synced', $partner, $result, null);
                }
            } catch (Throwable $exception) {
                $allOk = false;
                Services::logger()->log('sync', $exception->getMessage(), 'error', $partner->id);

                if ($report !== null) {
                    $report('failed', $partner, null, $exception);
                }
            }
        }

        update_option(self::LAST_RUN_OPTION, gmdate('Y-m-d H:i:s'), false);

        return $allOk;
    }

    /**
     * True when partners exist but no sync pass has completed within the
     * watchdog threshold — wp-cron is probably asleep.
     */
    public static function isOverdue(): bool
    {
        if (Services::partners()->syncable() === []) {
            return false;
        }

        $lastRun = get_option(self::LAST_RUN_OPTION, '');

        if (! is_string($lastRun) || $lastRun === '') {
            return true;
        }

        $timestamp = strtotime($lastRun . ' UTC');

        return $timestamp === false || $timestamp < time() - self::STALE_RUN_SECONDS;
    }
}

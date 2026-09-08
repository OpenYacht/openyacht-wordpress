<?php

declare(strict_types=1);

namespace OpenYacht;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Schedules the push delivery worker on wp-cron. One single event at a
 * time: a request for an earlier run replaces a later one, a request for
 * a later run is dropped in favour of the one already coming.
 */
final class PushSchedule
{
    public const HOOK = 'openyacht_push_deliver';

    public static function drainAt(int $timestamp): void
    {
        $next = wp_next_scheduled(self::HOOK);

        if ($next !== false && $next <= $timestamp) {
            return;
        }

        if ($next !== false) {
            wp_unschedule_event($next, self::HOOK);
        }

        wp_schedule_single_event($timestamp, self::HOOK);
    }
}

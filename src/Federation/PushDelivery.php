<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One pending push delivery: a (partner, listing) pair whose current
 * state is owed to the partner's callback. The row carries only retry
 * bookkeeping — the payload is derived when the delivery runs, so a
 * retry after a later edit sends the newer state and a burst of edits
 * coalesces into one row.
 *
 * // api-design.md §Subscriptions
 */
final class PushDelivery
{
    /**
     * @param string $queuedAt when the row was first queued — the 24-hour
     *                         retry window is measured from here
     * @param string $changedAt the latest change folded into this row; a
     *                          delivery completes only if nothing newer
     *                          was folded in while it was in flight
     */
    public function __construct(
        public readonly int $id,
        public readonly int $partnerId,
        public readonly int $listingId,
        public readonly string $queuedAt,
        public readonly string $changedAt,
        public readonly string $nextAttemptAt,
        public readonly int $attempts,
        public readonly ?string $lastError,
    ) {
    }
}

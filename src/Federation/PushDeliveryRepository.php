<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The push delivery queue: one live row per (partner, listing), so a
 * change to a listing already waiting is folded into the waiting row
 * rather than queued twice.
 */
interface PushDeliveryRepository
{
    /**
     * Queue a delivery for the pair, or fold the change into the row
     * already waiting (its changed_at moves; its retry schedule does not).
     */
    public function enqueue(int $partnerId, int $listingId, string $nowStored): void;

    /**
     * Rows whose next attempt is due at or before $nowStored, earliest
     * first.
     *
     * @return list<PushDelivery>
     */
    public function due(string $nowStored, int $limit): array;

    /**
     * @return list<PushDelivery>
     */
    public function all(): array;

    /**
     * Remove a delivered row — unless a newer change was folded in while
     * it was in flight, in which case the row stays and the caller sends
     * again. Returns whether the row was removed.
     */
    public function complete(int $id, string $changedAtStored): bool;

    public function delete(int $id): void;

    public function deleteForPartner(int $partnerId): void;

    public function recordFailure(int $id, int $attempts, string $nextAttemptAtStored, string $error): void;

    public function reschedule(int $id, string $nextAttemptAtStored): void;

    public function earliestNextAttempt(): ?string;
}

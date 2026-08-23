<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Append-only per-partner visibility transitions (became-hidden /
 * became-visible). An event log, not a flag: the re-share case — unshare
 * then re-share must surface as a tombstone THEN a normal listing again
 * against an arbitrary updated_since watermark — cannot be expressed by a
 * lone hidden_at column.
 *
 * // wordpress-plugin-notes.md §Granular sharing (portability notes)
 */
interface VisibilityEventRepository
{
    public const HIDDEN = 'hidden';

    public const VISIBLE = 'visible';

    public function append(int $listingId, int $partnerId, string $event, ?string $occurredAtStored = null): void;

    /**
     * The latest event per listing for one partner.
     *
     * @return array<int, array{event: string, occurred_at: string}> listing id => latest event
     */
    public function latestForPartner(int $partnerId): array;
}

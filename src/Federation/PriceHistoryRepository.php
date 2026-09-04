<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Append-only price history (LS-10): most-recent-first, first entry equals
 * the current price. Rows are never rewritten.
 */
interface PriceHistoryRepository
{
    public function append(int $listingId, string $amount, string $currency, ?string $changedAtStored = null): void;

    /**
     * @return list<array{amount: string, currency: string, changed_at: string}> most recent first
     */
    public function forListing(int $listingId): array;
}

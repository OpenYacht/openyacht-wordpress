<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Read-only price history for candidate validation.
 */
final class FixedPriceHistory implements PriceHistoryRepository
{
    /**
     * @param list<array{amount: string, currency: string, changed_at: string}> $entries
     */
    public function __construct(private readonly array $entries = [])
    {
    }

    public function append(int $listingId, string $amount, string $currency, ?string $changedAtStored = null): void
    {
        throw new RuntimeException('FixedPriceHistory is read-only.');
    }

    public function forListing(int $listingId): array
    {
        return $this->entries;
    }
}

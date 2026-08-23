<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\PriceHistoryRepository;

final class InMemoryPriceHistoryRepository implements PriceHistoryRepository
{
    /** @var list<array{listing_id: int, amount: string, currency: string, changed_at: string}> */
    public array $entries = [];

    public function append(int $listingId, string $amount, string $currency, ?string $changedAtStored = null): void
    {
        $this->entries[] = [
            'listing_id' => $listingId,
            'amount' => $amount,
            'currency' => $currency,
            'changed_at' => $changedAtStored ?? gmdate('Y-m-d H:i:s'),
        ];
    }

    public function forListing(int $listingId): array
    {
        $entries = array_values(array_filter(
            $this->entries,
            static fn (array $entry): bool => $entry['listing_id'] === $listingId,
        ));
        $entries = array_reverse($entries);

        return array_map(
            static fn (array $entry): array => [
                'amount' => $entry['amount'],
                'currency' => $entry['currency'],
                'changed_at' => $entry['changed_at'],
            ],
            $entries,
        );
    }
}

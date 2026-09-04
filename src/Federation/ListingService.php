<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use InvalidArgumentException;

/**
 * Authoring rules for own listings, enforced in one place regardless of
 * entry path (admin UI, ingest API, CLI seed): the canonical UUID is
 * minted once (ID-1), every change stamps federation_updated_at (API-4:
 * the updated_at a consumer sees must reflect the reported change), price
 * changes append to history (LS-10), and status transitions follow the
 * lifecycle (ID-8).
 */
final class ListingService
{
    public function __construct(
        private readonly ListingRepository $listings,
        private readonly PriceHistoryRepository $prices,
    ) {
    }

    /**
     * @param array<string, mixed> $columns
     */
    public function create(array $columns): Listing
    {
        $columns['uuid'] = wp_generate_uuid4();
        $columns['status'] ??= ListingStatus::Draft;
        $columns['federation_updated_at'] = gmdate('Y-m-d H:i:s');

        $listing = $this->listings->insert($columns);
        $this->appendPriceHistoryIfPriced($listing);

        do_action('openyacht_listing_created', $listing);

        return $listing;
    }

    /**
     * @param array<string, mixed> $columns
     */
    public function update(Listing $listing, array $columns): Listing
    {
        if (isset($columns['uuid']) && $columns['uuid'] !== $listing->uuid) {
            throw new InvalidArgumentException('The canonical UUID never changes for the life of the listing.');
        }

        $columns['federation_updated_at'] = gmdate('Y-m-d H:i:s');
        $this->listings->update($listing->id, $columns);

        $fresh = $this->listings->find($listing->id) ?? $listing;

        if ($fresh->priceAmount !== $listing->priceAmount || $fresh->priceCurrency !== $listing->priceCurrency) {
            $this->appendPriceHistoryIfPriced($fresh);
        }

        do_action('openyacht_listing_updated', $fresh);

        return $fresh;
    }

    /**
     * Apply a lifecycle transition, rejecting anything the lifecycle does
     * not allow (ID-8).
     */
    public function transition(Listing $listing, ListingStatus $target): Listing
    {
        if (! $listing->canTransitionTo($target)) {
            throw new InvalidArgumentException("A {$listing->status->value} listing cannot become {$target->value}.");
        }

        $columns = [
            'status' => $target,
            'federation_updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($target === ListingStatus::Active && $listing->listedAt === null) {
            $columns['listed_at'] = gmdate('Y-m-d H:i:s');
        }

        $this->listings->update($listing->id, $columns);
        $fresh = $this->listings->find($listing->id) ?? $listing;

        do_action('openyacht_listing_transitioned', $fresh, $listing->status);

        return $fresh;
    }

    private function appendPriceHistoryIfPriced(Listing $listing): void
    {
        if ($listing->priceAmount !== null && $listing->priceCurrency !== null) {
            $this->prices->append($listing->id, $listing->priceAmount, $listing->priceCurrency);
        }
    }
}

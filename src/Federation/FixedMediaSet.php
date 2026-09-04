<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Read-only media set for candidate validation: lets the serializer build
 * a wire view from not-yet-persisted media.
 */
final class FixedMediaSet implements ListingMediaRepository
{
    /**
     * @param list<ListingMedia> $items
     */
    public function __construct(private readonly array $items)
    {
    }

    public function forListing(int $listingId): array
    {
        $items = $this->items;
        usort($items, static fn (ListingMedia $a, ListingMedia $b): int => [$a->kind !== 'profile', $a->sort] <=> [$b->kind !== 'profile', $b->sort]);

        return $items;
    }

    public function insert(array $columns): void
    {
        throw new RuntimeException('FixedMediaSet is read-only.');
    }

    public function deleteForListing(int $listingId): void
    {
        throw new RuntimeException('FixedMediaSet is read-only.');
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

interface ListingMediaRepository
{
    /**
     * @return list<ListingMedia> profile first, then by kind and sort
     */
    public function forListing(int $listingId): array;

    /**
     * @param array<string, mixed> $columns
     */
    public function insert(array $columns): void;

    public function deleteForListing(int $listingId): void;
}

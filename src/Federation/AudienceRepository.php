<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * The selected-partners set for listings whose audience is 'selected'.
 */
interface AudienceRepository
{
    /**
     * @return list<int> partner ids
     */
    public function partnersForListing(int $listingId): array;

    /**
     * @param list<int> $partnerIds
     */
    public function replaceForListing(int $listingId, array $partnerIds): void;
}

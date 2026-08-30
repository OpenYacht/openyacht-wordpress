<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * The explicitly-selected-partners set per listing. Pivot rows are
 * additive: they grant visibility under any audience except 'none', so
 * they persist across audience changes (a curated partner is reachable
 * ONLY through them).
 */
interface AudienceRepository
{
    /**
     * @return list<int> partner ids
     */
    public function partnersForListing(int $listingId): array;

    /**
     * @return list<int> listing ids directly shared with the partner
     */
    public function listingIdsForPartner(int $partnerId): array;

    /**
     * @param list<int> $partnerIds
     */
    public function replaceForListing(int $listingId, array $partnerIds): void;
}

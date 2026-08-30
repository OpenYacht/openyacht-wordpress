<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\AudienceRepository;

final class InMemoryAudienceRepository implements AudienceRepository
{
    /** @var array<int, list<int>> listing id => partner ids */
    public array $selected = [];

    public function partnersForListing(int $listingId): array
    {
        return $this->selected[$listingId] ?? [];
    }

    public function listingIdsForPartner(int $partnerId): array
    {
        $ids = [];

        foreach ($this->selected as $listingId => $partnerIds) {
            if (in_array($partnerId, $partnerIds, true)) {
                $ids[] = $listingId;
            }
        }

        return $ids;
    }

    public function replaceForListing(int $listingId, array $partnerIds): void
    {
        $this->selected[$listingId] = array_values(array_unique(array_map('intval', $partnerIds)));
    }
}

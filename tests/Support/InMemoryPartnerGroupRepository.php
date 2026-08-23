<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\PartnerGroup;
use OpenYacht\Federation\PartnerGroupRepository;

final class InMemoryPartnerGroupRepository implements PartnerGroupRepository
{
    /** @var array<int, PartnerGroup> */
    public array $groups = [];

    /** @var array<int, list<int>> group id => partner ids */
    public array $members = [];

    /** @var array<int, list<int>> listing id => group ids */
    public array $listingGroups = [];

    private int $nextId = 1;

    public function all(): array
    {
        return array_values($this->groups);
    }

    public function find(int $id): ?PartnerGroup
    {
        return $this->groups[$id] ?? null;
    }

    public function create(string $name): PartnerGroup
    {
        $group = new PartnerGroup($this->nextId++, $name);
        $this->groups[$group->id] = $group;

        return $group;
    }

    public function rename(int $id, string $name): void
    {
        if (isset($this->groups[$id])) {
            $this->groups[$id] = new PartnerGroup($id, $name);
        }
    }

    public function delete(int $id): void
    {
        unset($this->groups[$id], $this->members[$id]);

        foreach ($this->listingGroups as $listingId => $groupIds) {
            $this->listingGroups[$listingId] = array_values(array_diff($groupIds, [$id]));
        }
    }

    public function members(int $groupId): array
    {
        return $this->members[$groupId] ?? [];
    }

    public function replaceMembers(int $groupId, array $partnerIds): void
    {
        $this->members[$groupId] = array_values(array_unique(array_map('intval', $partnerIds)));
    }

    public function groupIdsForPartner(int $partnerId): array
    {
        $ids = [];

        foreach ($this->members as $groupId => $partnerIds) {
            if (in_array($partnerId, $partnerIds, true)) {
                $ids[] = $groupId;
            }
        }

        return $ids;
    }

    public function groupIdsForListing(int $listingId): array
    {
        return $this->listingGroups[$listingId] ?? [];
    }

    public function replaceForListing(int $listingId, array $groupIds): void
    {
        $this->listingGroups[$listingId] = array_values(array_unique(array_map('intval', $groupIds)));
    }

    public function listingIdsSelectingGroup(int $groupId): array
    {
        $ids = [];

        foreach ($this->listingGroups as $listingId => $groupIds) {
            if (in_array($groupId, $groupIds, true)) {
                $ids[] = $listingId;
            }
        }

        return $ids;
    }
}

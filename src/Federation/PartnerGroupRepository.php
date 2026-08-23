<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Node-defined partner groups, their membership, and the per-listing
 * selected-groups set that composes with the selected-partners set.
 */
interface PartnerGroupRepository
{
    /**
     * @return list<PartnerGroup>
     */
    public function all(): array;

    public function find(int $id): ?PartnerGroup;

    public function create(string $name): PartnerGroup;

    public function rename(int $id, string $name): void;

    /**
     * Deletes the group, its membership rows, and its listing selections.
     * Call SharingService::replaceGroupMembers($id, []) FIRST so the
     * visibility transitions are recorded before the rows vanish.
     */
    public function delete(int $id): void;

    /**
     * @return list<int> partner ids
     */
    public function members(int $groupId): array;

    /**
     * @param list<int> $partnerIds
     */
    public function replaceMembers(int $groupId, array $partnerIds): void;

    /**
     * @return list<int> group ids the partner belongs to
     */
    public function groupIdsForPartner(int $partnerId): array;

    /**
     * @return list<int> group ids selected on the listing
     */
    public function groupIdsForListing(int $listingId): array;

    /**
     * @param list<int> $groupIds
     */
    public function replaceForListing(int $listingId, array $groupIds): void;

    /**
     * @return list<int> listing ids whose audience selects this group
     */
    public function listingIdsSelectingGroup(int $groupId): array;
}

<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

interface ListingRepository
{
    public function find(int $id): ?Listing;

    public function findByUuid(string $uuid): ?Listing;

    /**
     * @param array<string, mixed> $columns
     */
    public function insert(array $columns): Listing;

    /**
     * @param array<string, mixed> $columns
     */
    public function update(int $id, array $columns): void;

    /**
     * One page of a partner's feed, keyset-ordered on
     * (effective_updated_at, id) so concurrent writes never skip or
     * duplicate rows. Drafts are never included (LS-7); audience rules
     * narrow the served set per partner.
     *
     * With $updatedSinceStored: everything whose federation-visible state
     * changed for THIS partner at or after the timestamp — content
     * changes and terminal transitions while visible, plus became-hidden
     * transitions (which serialise as tombstones) and became-visible
     * transitions (which resurface the listing) (API-3). Without: the
     * inventory currently visible to the partner (cold sync).
     *
     * Returns up to $limit + 1 rows so the caller can detect a next page.
     *
     * @param array{updated_at: string, id: int}|null $cursor stored-format position
     * @return list<FeedItem>
     */
    public function feedPage(int $partnerId, ?string $updatedSinceStored, ?array $cursor, int $limit): array;

    public function countAll(): int;

    /**
     * Hard delete — ONLY for rolling back a failed ingest before the
     * listing was ever distributed. Published listings are never deleted;
     * they are withdrawn (ID-8) and tombstoned.
     */
    public function delete(int $id): void;
}

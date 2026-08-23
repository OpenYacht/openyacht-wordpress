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
     * One serving page, keyset-ordered on (federation_updated_at, id) so
     * concurrent writes never skip or duplicate rows. Drafts are never
     * included (LS-7). With $updatedSinceStored: everything whose
     * federation-visible state changed at or after the timestamp,
     * terminal listings included (they serialise as tombstones, API-3).
     * Without: the currently visible inventory only (cold sync).
     *
     * Returns up to $limit + 1 rows so the caller can detect a next page.
     *
     * @param array{updated_at: string, id: int}|null $cursor stored-format position
     * @return list<Listing>
     */
    public function page(?string $updatedSinceStored, ?array $cursor, int $limit): array;

    public function countAll(): int;

    /**
     * Hard delete — ONLY for rolling back a failed ingest before the
     * listing was ever distributed. Published listings are never deleted;
     * they are withdrawn (ID-8) and tombstoned.
     */
    public function delete(int $id): void;
}

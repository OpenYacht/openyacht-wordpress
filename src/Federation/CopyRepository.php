<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

interface CopyRepository
{
    public function findForPartner(int $partnerId, string $canonicalUri): ?ListingCopy;

    /**
     * Insert or update the copy for (partner, canonical URI) — the URI is
     * compared as an opaque string (ID-2).
     *
     * @param array<string, mixed> $item the verbatim wire listing object
     * @return array{copy: ListingCopy, created: bool}
     */
    public function upsert(Partner $partner, string $canonicalUri, array $item): array;

    /**
     * Mark a live copy tombstoned. Returns the tombstoned copy, or null if
     * no live copy existed for the URI.
     */
    public function tombstone(int $partnerId, string $canonicalUri, string $status, ?string $listingUpdatedAt): ?ListingCopy;

    public function countForPartner(int $partnerId): int;
}

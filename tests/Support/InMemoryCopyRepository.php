<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\CopyRepository;
use OpenYacht\Federation\ListingCopy;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Federation\Partner;

final class InMemoryCopyRepository implements CopyRepository
{
    /** @var array<int, ListingCopy> */
    public array $copies = [];

    private int $nextId = 1;

    public function findForPartner(int $partnerId, string $canonicalUri): ?ListingCopy
    {
        foreach ($this->copies as $copy) {
            if ($copy->partnerId === $partnerId && $copy->canonicalUri === $canonicalUri) {
                return $copy;
            }
        }

        return null;
    }

    public function upsert(Partner $partner, string $canonicalUri, array $item): array
    {
        $existing = $this->findForPartner($partner->id, $canonicalUri);
        $id = $existing?->id ?? $this->nextId++;

        $copy = new ListingCopy(
            id: $id,
            partnerId: $partner->id,
            canonicalUri: $canonicalUri,
            authorityDomain: $partner->domain,
            type: is_string($item['type'] ?? null) ? $item['type'] : 'sale',
            status: (ListingStatus::tryFrom((string) ($item['status'] ?? '')) ?? ListingStatus::Active)->value,
            name: is_string($item['listing']['name'] ?? null) ? $item['listing']['name'] : null,
            payload: $item,
            listingUpdatedAt: is_string($item['updated_at'] ?? null) ? $item['updated_at'] : null,
            receivedAt: gmdate('Y-m-d H:i:s'),
            signatureVerified: true,
            tombstonedAt: null,
        );

        $this->copies[$id] = $copy;

        return ['copy' => $copy, 'created' => $existing === null];
    }

    public function tombstone(int $partnerId, string $canonicalUri, string $status, ?string $listingUpdatedAt): ?ListingCopy
    {
        $existing = $this->findForPartner($partnerId, $canonicalUri);

        if ($existing === null || $existing->tombstonedAt !== null) {
            return null;
        }

        $copy = new ListingCopy(
            id: $existing->id,
            partnerId: $existing->partnerId,
            canonicalUri: $existing->canonicalUri,
            authorityDomain: $existing->authorityDomain,
            type: $existing->type,
            status: $status,
            name: $existing->name,
            payload: $existing->payload,
            listingUpdatedAt: $listingUpdatedAt ?? $existing->listingUpdatedAt,
            receivedAt: $existing->receivedAt,
            signatureVerified: $existing->signatureVerified,
            tombstonedAt: gmdate('Y-m-d H:i:s'),
        );

        $this->copies[$existing->id] = $copy;

        return $copy;
    }

    public function countForPartner(int $partnerId): int
    {
        return count(array_filter(
            $this->copies,
            static fn (ListingCopy $copy): bool => $copy->partnerId === $partnerId,
        ));
    }
}

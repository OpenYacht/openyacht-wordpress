<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * A synced copy of a partner's listing, stored verbatim with the mandatory
 * provenance fields (ID-3) and never substantively modified (ID-5).
 *
 * // yacht-identity.md §What everyone else holds
 */
final class ListingCopy
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly int $partnerId,
        public readonly string $canonicalUri,
        public readonly string $authorityDomain,
        public readonly string $type,
        public readonly string $status,
        public readonly ?string $name,
        public readonly array $payload,
        public readonly ?string $listingUpdatedAt,
        public readonly string $receivedAt,
        public readonly bool $signatureVerified,
        public readonly ?string $tombstonedAt,
    ) {
    }
}

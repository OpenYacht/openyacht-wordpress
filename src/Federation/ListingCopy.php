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
        public readonly ?string $selectedAt = null,
    ) {
    }

    /**
     * Selected for import: media is cached and the display layer projects
     * it. Unselected copies stay lightweight — JSON plus the authority's
     * own thumbnail for previews.
     */
    public function isSelected(): bool
    {
        return $this->selectedAt !== null;
    }

    /**
     * The authority-hosted preview thumbnail (LS-8 mandates it whenever
     * the listing has imagery) — the wire carries it exactly so pickers
     * need no local image pipeline.
     */
    public function thumbnailUrl(): ?string
    {
        $url = $this->payload['media']['profile']['thumbnail_url'] ?? null;

        return is_string($url) && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }
}

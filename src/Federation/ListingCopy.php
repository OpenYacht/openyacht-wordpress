<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

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

    /**
     * Authority-hosted gallery thumbnails, in wire sort order. Nullable on
     * the wire (LS-16) and absent entirely in pre-amendment payloads, so
     * this is only the items that actually carry one — pre-import previews
     * never hotlink the full-resolution url.
     *
     * @return list<array{thumbnail_url: string, url: ?string, caption: ?string}>
     */
    public function galleryThumbnails(): array
    {
        $gallery = $this->payload['media']['gallery'] ?? null;
        $thumbnails = [];

        foreach (is_array($gallery) ? $gallery : [] as $item) {
            $thumbnail = is_array($item) ? ($item['thumbnail_url'] ?? null) : null;

            if (! is_string($thumbnail) || ! str_starts_with(strtolower($thumbnail), 'https://')) {
                continue;
            }

            $url = $item['url'] ?? null;
            $caption = $item['caption'] ?? null;
            $thumbnails[] = [
                'thumbnail_url' => $thumbnail,
                'url' => is_string($url) && str_starts_with(strtolower($url), 'https://') ? $url : null,
                'caption' => is_string($caption) ? $caption : null,
            ];
        }

        return $thumbnails;
    }
}

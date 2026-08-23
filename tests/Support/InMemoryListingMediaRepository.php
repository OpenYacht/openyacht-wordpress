<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\ListingMedia;
use OpenYacht\Federation\ListingMediaRepository;

final class InMemoryListingMediaRepository implements ListingMediaRepository
{
    /** @var list<ListingMedia> */
    public array $items = [];

    private int $nextId = 1;

    public function forListing(int $listingId): array
    {
        $items = array_values(array_filter(
            $this->items,
            static fn (ListingMedia $item): bool => $item->listingId === $listingId,
        ));

        usort($items, static fn (ListingMedia $a, ListingMedia $b): int => [$a->kind !== 'profile', $a->sort] <=> [$b->kind !== 'profile', $b->sort]);

        return $items;
    }

    public function insert(array $columns): void
    {
        $string = static fn (mixed $value): ?string => isset($value) && $value !== '' ? (string) $value : null;

        $this->items[] = new ListingMedia(
            id: $this->nextId++,
            listingId: (int) $columns['listing_id'],
            kind: (string) $columns['kind'],
            attachmentId: isset($columns['attachment_id']) && $columns['attachment_id'] !== null ? (int) $columns['attachment_id'] : null,
            url: $string($columns['url'] ?? null),
            thumbnailUrl: $string($columns['thumbnail_url'] ?? null),
            sha256: $string($columns['sha256'] ?? null),
            width: isset($columns['width']) && $columns['width'] !== null ? (int) $columns['width'] : null,
            height: isset($columns['height']) && $columns['height'] !== null ? (int) $columns['height'] : null,
            caption: $string($columns['caption'] ?? null),
            category: $string($columns['category'] ?? null),
            sort: (int) ($columns['sort'] ?? 0),
        );
    }

    public function deleteForListing(int $listingId): void
    {
        $this->items = array_values(array_filter(
            $this->items,
            static fn (ListingMedia $item): bool => $item->listingId !== $listingId,
        ));
    }
}

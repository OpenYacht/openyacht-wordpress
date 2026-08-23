<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\Listing;
use OpenYacht\Federation\ListingRepository;
use OpenYacht\Federation\ListingStatus;

final class InMemoryListingRepository implements ListingRepository
{
    /** @var array<int, Listing> */
    public array $listings = [];

    private int $nextId = 1;

    public function __construct(
        public ?InMemoryAudienceRepository $audience = null,
        public ?InMemoryVisibilityEventRepository $events = null,
        public ?InMemoryPartnerGroupRepository $groups = null,
    ) {
    }

    public function find(int $id): ?Listing
    {
        return $this->listings[$id] ?? null;
    }

    public function findByUuid(string $uuid): ?Listing
    {
        foreach ($this->listings as $listing) {
            if ($listing->uuid === $uuid) {
                return $listing;
            }
        }

        return null;
    }

    public function insert(array $columns): Listing
    {
        $listing = $this->build($this->nextId++, $columns);
        $this->listings[$listing->id] = $listing;

        return $listing;
    }

    public function update(int $id, array $columns): void
    {
        $existing = $this->listings[$id] ?? null;

        if ($existing === null) {
            return;
        }

        unset($columns['id'], $columns['uuid']);
        $this->listings[$id] = $this->build($id, $columns + $this->toColumns($existing));
    }

    public function feedPage(int $partnerId, ?string $updatedSinceStored, ?array $cursor, int $limit): array
    {
        $latest = $this->events?->latestForPartner($partnerId) ?? [];
        $items = [];

        foreach ($this->listings as $listing) {
            if ($listing->status === ListingStatus::Draft) {
                continue;
            }

            $visible = match ($listing->audience) {
                \OpenYacht\Federation\Audience::Everyone => true,
                \OpenYacht\Federation\Audience::None => false,
                \OpenYacht\Federation\Audience::Selected => in_array($partnerId, $this->audience?->partnersForListing($listing->id) ?? [], true)
                    || array_intersect(
                        $this->groups?->groupIdsForListing($listing->id) ?? [],
                        $this->groups?->groupIdsForPartner($partnerId) ?? [],
                    ) !== [],
            };
            $event = $latest[$listing->id] ?? null;
            $effective = max((string) $listing->federationUpdatedAt, $event['occurred_at'] ?? '');

            if ($updatedSinceStored === null) {
                $include = $visible && in_array($listing->status, [ListingStatus::Active, ListingStatus::UnderOffer], true);
            } else {
                $include = ($visible && $effective >= $updatedSinceStored)
                    || (! $visible && ($event['event'] ?? '') === 'hidden' && $event['occurred_at'] >= $updatedSinceStored);
            }

            if (! $include) {
                continue;
            }

            if ($cursor !== null && ! ([$effective, $listing->id] > [$cursor['updated_at'], $cursor['id']])) {
                continue;
            }

            $items[] = new \OpenYacht\Federation\FeedItem($listing, $visible, $effective, $event['event'] ?? null);
        }

        usort($items, static fn ($a, $b): int => [$a->effectiveUpdatedAt, $a->listing->id] <=> [$b->effectiveUpdatedAt, $b->listing->id]);

        return array_slice($items, 0, $limit + 1);
    }

    public function countAll(): int
    {
        return count($this->listings);
    }

    public function delete(int $id): void
    {
        unset($this->listings[$id]);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function build(int $id, array $columns): Listing
    {
        return \OpenYacht\Federation\ListingFactory::fromColumns($id, (string) ($columns['uuid'] ?? ''), $columns);
    }

    /**
     * @return array<string, mixed>
     */
    private function toColumns(Listing $listing): array
    {
        return ['uuid' => $listing->uuid] + \OpenYacht\Federation\ListingFactory::toColumns($listing);
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\VisibilityEventRepository;

final class InMemoryVisibilityEventRepository implements VisibilityEventRepository
{
    /** @var list<array{listing_id: int, partner_id: int, event: string, occurred_at: string}> */
    public array $events = [];

    public function append(int $listingId, int $partnerId, string $event, ?string $occurredAtStored = null): void
    {
        $this->events[] = [
            'listing_id' => $listingId,
            'partner_id' => $partnerId,
            'event' => $event,
            'occurred_at' => $occurredAtStored ?? gmdate('Y-m-d H:i:s'),
        ];
    }

    public function latestForPartner(int $partnerId): array
    {
        $latest = [];

        foreach ($this->events as $event) {
            if ($event['partner_id'] === $partnerId) {
                $latest[$event['listing_id']] = ['event' => $event['event'], 'occurred_at' => $event['occurred_at']];
            }
        }

        return $latest;
    }
}

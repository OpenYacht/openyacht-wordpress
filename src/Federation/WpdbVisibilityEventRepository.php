<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use OpenYacht\Schema;

final class WpdbVisibilityEventRepository implements VisibilityEventRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function append(int $listingId, int $partnerId, string $event, ?string $occurredAtStored = null): void
    {
        $this->wpdb->insert($this->table(), [
            'listing_id' => $listingId,
            'partner_id' => $partnerId,
            'event' => $event,
            'occurred_at' => $occurredAtStored ?? gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function latestForPartner(int $partnerId): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT e.listing_id, e.event, e.occurred_at
             FROM {$this->table()} e
             JOIN (SELECT listing_id, MAX(id) AS max_id FROM {$this->table()} WHERE partner_id = %d GROUP BY listing_id) latest
               ON latest.max_id = e.id",
            $partnerId,
        ), 'ARRAY_A');

        $latest = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $latest[(int) $row['listing_id']] = [
                'event' => (string) $row['event'],
                'occurred_at' => (string) $row['occurred_at'],
            ];
        }

        return $latest;
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('visibility_events');
    }
}

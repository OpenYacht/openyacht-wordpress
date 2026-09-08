<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

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

    public function latestFor(int $listingId, int $partnerId): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT event, occurred_at FROM {$this->table()} WHERE listing_id = %d AND partner_id = %d ORDER BY id DESC LIMIT 1",
            $listingId,
            $partnerId,
        ), 'ARRAY_A');

        return is_array($row) ? ['event' => (string) $row['event'], 'occurred_at' => (string) $row['occurred_at']] : null;
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('visibility_events');
    }
}

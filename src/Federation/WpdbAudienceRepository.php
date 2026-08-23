<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use OpenYacht\Schema;

final class WpdbAudienceRepository implements AudienceRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function partnersForListing(int $listingId): array
    {
        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT partner_id FROM {$this->table()} WHERE listing_id = %d",
            $listingId,
        ));

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function replaceForListing(int $listingId, array $partnerIds): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE listing_id = %d",
            $listingId,
        ));

        foreach (array_unique(array_map('intval', $partnerIds)) as $partnerId) {
            $this->wpdb->insert($this->table(), [
                'listing_id' => $listingId,
                'partner_id' => $partnerId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('listing_audience');
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Schema;

final class WpdbPriceHistoryRepository implements PriceHistoryRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function append(int $listingId, string $amount, string $currency, ?string $changedAtStored = null): void
    {
        $this->wpdb->insert($this->table(), [
            'listing_id' => $listingId,
            'amount' => $amount,
            'currency' => $currency,
            'changed_at' => $changedAtStored ?? gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function forListing(int $listingId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT amount, currency, changed_at FROM {$this->table()} WHERE listing_id = %d ORDER BY changed_at DESC, id DESC",
                $listingId,
            ),
            'ARRAY_A',
        );

        $entries = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $entries[] = [
                'amount' => (string) $row['amount'],
                'currency' => (string) $row['currency'],
                'changed_at' => (string) $row['changed_at'],
            ];
        }

        return $entries;
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('price_history');
    }
}

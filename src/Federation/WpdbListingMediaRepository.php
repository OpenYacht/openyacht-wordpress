<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Schema;

final class WpdbListingMediaRepository implements ListingMediaRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function forListing(int $listingId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE listing_id = %d ORDER BY kind = 'profile' DESC, kind, sort",
                $listingId,
            ),
            'ARRAY_A',
        );

        $items = [];
        $string = static fn (mixed $value): ?string => isset($value) && $value !== '' ? (string) $value : null;

        foreach (is_array($rows) ? $rows : [] as $row) {
            $items[] = new ListingMedia(
                id: (int) $row['id'],
                listingId: (int) $row['listing_id'],
                kind: (string) $row['kind'],
                attachmentId: isset($row['attachment_id']) && (int) $row['attachment_id'] > 0 ? (int) $row['attachment_id'] : null,
                url: $string($row['url'] ?? null),
                thumbnailUrl: $string($row['thumbnail_url'] ?? null),
                sha256: $string($row['sha256'] ?? null),
                width: isset($row['width']) && $row['width'] !== '' ? (int) $row['width'] : null,
                height: isset($row['height']) && $row['height'] !== '' ? (int) $row['height'] : null,
                caption: $string($row['caption'] ?? null),
                category: $string($row['category'] ?? null),
                sort: (int) ($row['sort'] ?? 0),
            );
        }

        return $items;
    }

    public function insert(array $columns): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->wpdb->insert($this->table(), $columns + ['created_at' => $now, 'updated_at' => $now]);
    }

    public function deleteForListing(int $listingId): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE listing_id = %d",
            $listingId,
        ));
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('listing_media');
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use OpenYacht\Schema;

final class WpdbCopyMediaRepository implements CopyMediaRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function forCopy(int $copyId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE copy_id = %d ORDER BY kind = 'profile' DESC, sort",
                $copyId,
            ),
            'ARRAY_A',
        );

        $items = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $renditions = is_string($row['renditions'] ?? null) ? json_decode($row['renditions'], true) : null;

            $items[] = new CopyMedia(
                id: (int) $row['id'],
                copyId: (int) $row['copy_id'],
                kind: (string) $row['kind'],
                sourceUrl: (string) $row['source_url'],
                sourceSha256: isset($row['source_sha256']) && $row['source_sha256'] !== '' ? (string) $row['source_sha256'] : null,
                caption: isset($row['caption']) && $row['caption'] !== '' ? (string) $row['caption'] : null,
                sort: (int) ($row['sort'] ?? 0),
                renditions: is_array($renditions) ? $renditions : [],
            );
        }

        return $items;
    }

    public function insert(
        int $copyId,
        string $kind,
        string $sourceUrl,
        ?string $sourceSha256,
        ?string $caption,
        int $sort,
        array $renditions,
    ): void {
        $now = gmdate('Y-m-d H:i:s');

        $this->wpdb->insert($this->table(), [
            'copy_id' => $copyId,
            'kind' => $kind,
            'source_url' => $sourceUrl,
            'source_sha256' => $sourceSha256,
            'caption' => $caption,
            'sort' => $sort,
            'path' => null,
            'renditions' => wp_json_encode($renditions),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function deleteForCopy(int $copyId): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE copy_id = %d",
            $copyId,
        ));
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('copy_media');
    }
}

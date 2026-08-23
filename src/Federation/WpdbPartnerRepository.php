<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use OpenYacht\Schema;
use RuntimeException;

final class WpdbPartnerRepository implements PartnerRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function find(int $id): ?Partner
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            'ARRAY_A',
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByDomain(string $domain): ?Partner
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE domain = %s", strtolower(trim($domain))),
            'ARRAY_A',
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function all(): array
    {
        $rows = $this->wpdb->get_results("SELECT * FROM {$this->table()} ORDER BY domain", 'ARRAY_A');

        return array_map($this->hydrate(...), is_array($rows) ? $rows : []);
    }

    public function syncable(): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE trust_level != %s ORDER BY domain",
                TrustLevel::Blocked->value,
            ),
            'ARRAY_A',
        );

        return array_map($this->hydrate(...), is_array($rows) ? $rows : []);
    }

    public function insert(array $columns): Partner
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->wpdb->insert($this->table(), $this->serialize($columns) + ['created_at' => $now, 'updated_at' => $now]);

        $partner = $this->findByDomain((string) $columns['domain']);

        if ($partner === null) {
            throw new RuntimeException('Failed to insert federation partner.');
        }

        return $partner;
    }

    public function update(int $id, array $columns): void
    {
        $this->wpdb->update(
            $this->table(),
            $this->serialize($columns) + ['updated_at' => gmdate('Y-m-d H:i:s')],
            ['id' => $id],
        );
    }

    public function incrementFailures(int $id): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table()} SET consecutive_failures = consecutive_failures + 1 WHERE id = %d",
            $id,
        ));
    }

    /**
     * @param array<string, mixed> $columns
     * @return array<string, mixed>
     */
    private function serialize(array $columns): array
    {
        if (array_key_exists('keys_json', $columns) && is_array($columns['keys_json'])) {
            $columns['keys_json'] = wp_json_encode($columns['keys_json']);
        }

        if (array_key_exists('field_groups', $columns) && is_array($columns['field_groups'])) {
            $columns['field_groups'] = wp_json_encode($columns['field_groups']);
        }

        if (array_key_exists('trust_level', $columns) && $columns['trust_level'] instanceof TrustLevel) {
            $columns['trust_level'] = $columns['trust_level']->value;
        }

        return $columns;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Partner
    {
        $keysJson = is_string($row['keys_json'] ?? null) ? json_decode($row['keys_json'], true) : null;
        $fieldGroups = is_string($row['field_groups'] ?? null) ? json_decode($row['field_groups'], true) : null;

        return new Partner(
            id: (int) $row['id'],
            domain: (string) $row['domain'],
            nodeUuid: isset($row['node_uuid']) && $row['node_uuid'] !== '' ? (string) $row['node_uuid'] : null,
            keysJson: is_array($keysJson) ? array_values($keysJson) : null,
            keysFetchedAt: $row['keys_fetched_at'] ?? null,
            pinnedKeyId: isset($row['pinned_key_id']) && $row['pinned_key_id'] !== '' ? (string) $row['pinned_key_id'] : null,
            trustLevel: TrustLevel::tryFrom((string) ($row['trust_level'] ?? '')) ?? TrustLevel::Provisional,
            fieldGroups: is_array($fieldGroups) ? array_values($fieldGroups) : null,
            approvedByUserId: isset($row['approved_by_user_id']) && (int) $row['approved_by_user_id'] > 0
                ? (int) $row['approved_by_user_id']
                : null,
            lastOkAt: $row['last_ok_at'] ?? null,
            consecutiveFailures: (int) ($row['consecutive_failures'] ?? 0),
            lastSyncedAt: $row['last_synced_at'] ?? null,
            lastAttemptedAt: $row['last_attempted_at'] ?? null,
        );
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('partners');
    }
}

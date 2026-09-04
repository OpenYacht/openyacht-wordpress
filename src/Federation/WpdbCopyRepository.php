<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Schema;
use RuntimeException;

final class WpdbCopyRepository implements CopyRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function find(int $id): ?ListingCopy
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d", $id),
            'ARRAY_A',
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function findByUri(string $canonicalUri): ?ListingCopy
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE canonical_uri = %s", $canonicalUri),
            'ARRAY_A',
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function active(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table()} WHERE tombstoned_at IS NULL ORDER BY listing_updated_at DESC",
            'ARRAY_A',
        );

        return array_map($this->hydrate(...), is_array($rows) ? $rows : []);
    }

    public function findForPartner(int $partnerId, string $canonicalUri): ?ListingCopy
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table()} WHERE partner_id = %d AND canonical_uri = %s",
                $partnerId,
                $canonicalUri,
            ),
            'ARRAY_A',
        );

        return is_array($row) ? $this->hydrate($row) : null;
    }

    public function upsert(Partner $partner, string $canonicalUri, array $item): array
    {
        $existing = $this->findForPartner($partner->id, $canonicalUri);
        $now = gmdate('Y-m-d H:i:s');

        $columns = [
            'authority_domain' => $partner->domain,
            'type' => is_string($item['type'] ?? null) ? $item['type'] : 'sale',
            'status' => (ListingStatus::tryFrom((string) ($item['status'] ?? '')) ?? ListingStatus::Active)->value,
            'name' => is_string($item['listing']['name'] ?? null) ? $item['listing']['name'] : null,
            'payload' => wp_json_encode($item, JSON_UNESCAPED_SLASHES),
            'listing_updated_at' => $this->toStored($item['updated_at'] ?? null),
            'received_at' => $now,
            // Obtained directly from the authority on its identity domain
            // over verified TLS, via a signed request.
            'signature_verified' => 1,
            'tombstoned_at' => null,
            'updated_at' => $now,
        ];

        if ($existing !== null) {
            $this->wpdb->update($this->table(), $columns, ['id' => $existing->id]);
        } else {
            $this->wpdb->insert($this->table(), $columns + [
                'partner_id' => $partner->id,
                'canonical_uri' => $canonicalUri,
                'created_at' => $now,
            ]);
        }

        $copy = $this->findForPartner($partner->id, $canonicalUri);

        if ($copy === null) {
            throw new RuntimeException("Failed to store listing copy for {$canonicalUri}.");
        }

        return ['copy' => $copy, 'created' => $existing === null];
    }

    public function tombstone(int $partnerId, string $canonicalUri, string $status, ?string $listingUpdatedAt): ?ListingCopy
    {
        $existing = $this->findForPartner($partnerId, $canonicalUri);

        if ($existing === null || $existing->tombstonedAt !== null) {
            return null;
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->wpdb->update($this->table(), [
            'status' => $status,
            'tombstoned_at' => $now,
            'listing_updated_at' => $this->toStored($listingUpdatedAt) ?? $now,
            'updated_at' => $now,
        ], ['id' => $existing->id]);

        return $this->findForPartner($partnerId, $canonicalUri);
    }

    public function setSelected(int $copyId, bool $selected): void
    {
        $this->wpdb->update($this->table(), [
            'selected_at' => $selected ? gmdate('Y-m-d H:i:s') : null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $copyId]);
    }

    public function selected(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table()} WHERE selected_at IS NOT NULL AND tombstoned_at IS NULL ORDER BY listing_updated_at DESC",
            'ARRAY_A',
        );

        return array_map($this->hydrate(...), is_array($rows) ? $rows : []);
    }

    public function countForPartner(int $partnerId): int
    {
        return (int) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE partner_id = %d",
            $partnerId,
        ));
    }

    /**
     * RFC 3339 wire timestamps to app-managed stored 'Y-m-d H:i:s' UTC.
     */
    private function toStored(mixed $rfc3339): ?string
    {
        if (! is_string($rfc3339) || $rfc3339 === '') {
            return null;
        }

        $timestamp = strtotime($rfc3339);

        return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ListingCopy
    {
        $payload = is_string($row['payload'] ?? null) ? json_decode($row['payload'], true) : null;

        return new ListingCopy(
            id: (int) $row['id'],
            partnerId: (int) $row['partner_id'],
            canonicalUri: (string) $row['canonical_uri'],
            authorityDomain: (string) $row['authority_domain'],
            type: (string) $row['type'],
            status: (string) $row['status'],
            name: isset($row['name']) && $row['name'] !== '' ? (string) $row['name'] : null,
            payload: is_array($payload) ? $payload : [],
            listingUpdatedAt: $row['listing_updated_at'] ?? null,
            receivedAt: (string) ($row['received_at'] ?? ''),
            signatureVerified: (bool) ($row['signature_verified'] ?? false),
            tombstonedAt: $row['tombstoned_at'] ?? null,
            selectedAt: isset($row['selected_at']) && $row['selected_at'] !== '' ? (string) $row['selected_at'] : null,
        );
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('copies');
    }
}

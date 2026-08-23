<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use OpenYacht\Schema;

final class WpdbKeyRepository implements KeyRepository
{
    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly KeyEncryption $encryption,
    ) {
    }

    public function save(FederationKey $key): void
    {
        if ($key->privateKey === null) {
            throw new \RuntimeException('Cannot store a key without its private half.');
        }

        $this->wpdb->insert($this->table(), [
            'key_id' => $key->keyId,
            'private_key' => $this->encryption->encrypt($key->privateKey),
            'public_key' => $key->publicKey,
            'status' => $key->status->value,
            'created_at' => $key->createdAt ?? gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function hasActiveKey(): bool
    {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()} WHERE status = %s",
            KeyStatus::Active->value,
        );

        return (int) $this->wpdb->get_var($sql) > 0;
    }

    public function activeKey(): ?FederationKey
    {
        $sql = $this->wpdb->prepare(
            "SELECT key_id, public_key, private_key, status, created_at FROM {$this->table()} WHERE status = %s ORDER BY id DESC LIMIT 1",
            KeyStatus::Active->value,
        );
        $row = $this->wpdb->get_row($sql, 'ARRAY_A');

        if (! is_array($row)) {
            return null;
        }

        return new FederationKey(
            keyId: (string) $row['key_id'],
            publicKey: (string) $row['public_key'],
            privateKey: $this->encryption->decrypt((string) $row['private_key']),
            status: KeyStatus::from((string) $row['status']),
            createdAt: (string) $row['created_at'],
        );
    }

    public function publishedKeys(): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT key_id, public_key, status, created_at FROM {$this->table()} WHERE status IN (%s, %s) ORDER BY id DESC",
            KeyStatus::Active->value,
            KeyStatus::Retiring->value,
        );
        $rows = $this->wpdb->get_results($sql, 'ARRAY_A');
        $keys = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $keys[] = new FederationKey(
                keyId: (string) $row['key_id'],
                publicKey: (string) $row['public_key'],
                privateKey: null,
                status: KeyStatus::from((string) $row['status']),
                createdAt: (string) $row['created_at'],
            );
        }

        return $keys;
    }

    public function markActiveKeysRetiring(): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table()} SET status = %s WHERE status = %s",
            KeyStatus::Retiring->value,
            KeyStatus::Active->value,
        ));
    }

    public function revokeRetiringKeys(): int
    {
        $result = $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table()} SET status = %s, retired_at = %s WHERE status = %s",
            KeyStatus::Revoked->value,
            gmdate('Y-m-d H:i:s'),
            KeyStatus::Retiring->value,
        ));

        return is_numeric($result) ? (int) $result : 0;
    }

    public function revokeAllKeys(): void
    {
        $this->wpdb->query($this->wpdb->prepare(
            "UPDATE {$this->table()} SET status = %s, retired_at = %s WHERE status != %s",
            KeyStatus::Revoked->value,
            gmdate('Y-m-d H:i:s'),
            KeyStatus::Revoked->value,
        ));
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('keys');
    }
}

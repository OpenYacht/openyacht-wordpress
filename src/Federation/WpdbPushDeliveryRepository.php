<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Schema;

final class WpdbPushDeliveryRepository implements PushDeliveryRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function enqueue(int $partnerId, int $listingId, string $nowStored): void
    {
        // The unique (partner, listing) key makes the insert fold into the
        // waiting row: only changed_at moves, so a retry keeps its slot.
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$this->table()} (partner_id, listing_id, queued_at, changed_at, next_attempt_at, attempts, created_at, updated_at)
             VALUES (%d, %d, %s, %s, %s, 0, %s, %s)
             ON DUPLICATE KEY UPDATE changed_at = %s, updated_at = %s",
            $partnerId,
            $listingId,
            $nowStored,
            $nowStored,
            $nowStored,
            $nowStored,
            $nowStored,
            $nowStored,
            $nowStored,
        ));
    }

    public function due(string $nowStored, int $limit): array
    {
        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE next_attempt_at <= %s ORDER BY next_attempt_at, id LIMIT %d",
            $nowStored,
            $limit,
        ), 'ARRAY_A');

        return array_map($this->hydrate(...), is_array($rows) ? $rows : []);
    }

    public function all(): array
    {
        $rows = $this->wpdb->get_results("SELECT * FROM {$this->table()} ORDER BY next_attempt_at, id", 'ARRAY_A');

        return array_map($this->hydrate(...), is_array($rows) ? $rows : []);
    }

    public function complete(int $id, string $changedAtStored): bool
    {
        $deleted = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE id = %d AND changed_at = %s",
            $id,
            $changedAtStored,
        ));

        return is_int($deleted) && $deleted > 0;
    }

    public function delete(int $id): void
    {
        $this->wpdb->delete($this->table(), ['id' => $id]);
    }

    public function deleteForPartner(int $partnerId): void
    {
        $this->wpdb->delete($this->table(), ['partner_id' => $partnerId]);
    }

    public function recordFailure(int $id, int $attempts, string $nextAttemptAtStored, string $error): void
    {
        $this->wpdb->update($this->table(), [
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAtStored,
            'last_error' => mb_substr($error, 0, 1000),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function reschedule(int $id, string $nextAttemptAtStored): void
    {
        $this->wpdb->update($this->table(), [
            'next_attempt_at' => $nextAttemptAtStored,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function earliestNextAttempt(): ?string
    {
        $value = $this->wpdb->get_var("SELECT MIN(next_attempt_at) FROM {$this->table()}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PushDelivery
    {
        return new PushDelivery(
            id: (int) $row['id'],
            partnerId: (int) $row['partner_id'],
            listingId: (int) $row['listing_id'],
            queuedAt: (string) $row['queued_at'],
            changedAt: (string) $row['changed_at'],
            nextAttemptAt: (string) $row['next_attempt_at'],
            attempts: (int) ($row['attempts'] ?? 0),
            lastError: isset($row['last_error']) && $row['last_error'] !== '' ? (string) $row['last_error'] : null,
        );
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('push_deliveries');
    }
}

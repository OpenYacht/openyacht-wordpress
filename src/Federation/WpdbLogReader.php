<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Schema;

/**
 * Read and retention side of the federation log. Logger stays write-only —
 * this backs the Activity admin screen and the daily prune. Only the
 * high-volume operational channels (request, sync) are ever pruned; the
 * audit channels (partner, keys, sharing, node) are kept forever.
 */
final class WpdbLogReader
{
    public const PRUNABLE_CHANNELS = ['request', 'sync'];

    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    /**
     * @param array{channel?: string, outcome?: string, partner_id?: int} $filters
     * @return list<array<string, mixed>>
     */
    public function rows(array $filters = [], int $perPage = 50, int $page = 1): array
    {
        [$where, $args] = $this->where($filters);
        $args[] = $perPage;
        $args[] = max(0, ($page - 1) * $perPage);

        $rows = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$this->table()}{$where} ORDER BY id DESC LIMIT %d OFFSET %d",
            $args,
        ), 'ARRAY_A');

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param array{channel?: string, outcome?: string, partner_id?: int} $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $args] = $this->where($filters);
        $sql = "SELECT COUNT(*) FROM {$this->table()}{$where}";

        return (int) $this->wpdb->get_var($args === [] ? $sql : $this->wpdb->prepare($sql, $args));
    }

    /**
     * Distinct channels present, for the filter dropdown.
     *
     * @return list<string>
     */
    public function channels(): array
    {
        $rows = $this->wpdb->get_col("SELECT DISTINCT channel FROM {$this->table()} ORDER BY channel");

        return is_array($rows) ? array_values(array_map('strval', $rows)) : [];
    }

    /**
     * Delete prunable-channel rows older than the retention window.
     * $days <= 0 means keep forever (no-op).
     */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count(self::PRUNABLE_CHANNELS), '%s'));
        $args = self::PRUNABLE_CHANNELS;
        $args[] = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        return (int) $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE channel IN ({$placeholders}) AND created_at < %s",
            $args,
        ));
    }

    /**
     * @param array{channel?: string, outcome?: string, partner_id?: int} $filters
     * @return array{0: string, 1: list<int|string>}
     */
    private function where(array $filters): array
    {
        $clauses = [];
        $args = [];

        if (isset($filters['channel']) && $filters['channel'] !== '') {
            $clauses[] = 'channel = %s';
            $args[] = $filters['channel'];
        }

        if (isset($filters['outcome']) && $filters['outcome'] !== '') {
            $clauses[] = 'outcome = %s';
            $args[] = $filters['outcome'];
        }

        if (isset($filters['partner_id']) && $filters['partner_id'] > 0) {
            $clauses[] = 'partner_id = %d';
            $args[] = $filters['partner_id'];
        }

        return [$clauses === [] ? '' : ' WHERE ' . implode(' AND ', $clauses), $args];
    }

    private function table(): string
    {
        return (new Schema($this->wpdb))->tableName('log');
    }
}

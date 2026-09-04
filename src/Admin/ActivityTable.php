<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Services;

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Read-only view over the federation log — an audit surface, so no row
 * actions, no bulk actions, no delete. Retention is policy (Settings),
 * not a button.
 */
final class ActivityTable extends \WP_List_Table
{
    private const PER_PAGE = 50;

    public function __construct()
    {
        parent::__construct(['singular' => 'activity-entry', 'plural' => 'activity-entries', 'ajax' => false]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'created_at' => __('Time (UTC)', 'openyacht'),
            'channel' => __('Channel', 'openyacht'),
            'outcome' => __('Outcome', 'openyacht'),
            'partner' => __('Partner', 'openyacht'),
            'message' => __('Event', 'openyacht'),
        ];
    }

    /**
     * @return array{channel?: string, outcome?: string, partner_id?: int}
     */
    public static function filters(): array
    {
        $filters = [];

        if (isset($_GET['channel']) && $_GET['channel'] !== '') {
            $filters['channel'] = sanitize_key(wp_unslash($_GET['channel']));
        }

        if (isset($_GET['outcome']) && $_GET['outcome'] !== '') {
            $filters['outcome'] = sanitize_key(wp_unslash($_GET['outcome']));
        }

        if (isset($_GET['partner_id']) && (int) $_GET['partner_id'] > 0) {
            $filters['partner_id'] = (int) $_GET['partner_id'];
        }

        return $filters;
    }

    public function prepare_items(): void
    {
        $filters = self::filters();
        $page = max(1, $this->get_pagenum());
        $reader = Services::logReader();

        $this->items = $reader->rows($filters, self::PER_PAGE, $page);
        $this->_column_headers = [$this->get_columns(), [], []];
        $this->set_pagination_args([
            'total_items' => $reader->count($filters),
            'per_page' => self::PER_PAGE,
        ]);
    }

    /**
     * @param array<string, mixed> $item
     */
    public function column_created_at($item): string
    {
        return '<span style="white-space:nowrap;">' . esc_html((string) $item['created_at']) . '</span>';
    }

    /**
     * @param array<string, mixed> $item
     */
    public function column_channel($item): string
    {
        return '<code>' . esc_html((string) $item['channel']) . '</code>';
    }

    /**
     * @param array<string, mixed> $item
     */
    public function column_outcome($item): string
    {
        $outcome = (string) ($item['outcome'] ?? '');

        if ($outcome === '') {
            return '—';
        }

        $bad = in_array($outcome, ['error', 'rejected', 'failed'], true) || str_contains($outcome, 'invalid');
        $color = $outcome === 'ok' ? '#00a32a' : ($bad ? '#b32d2e' : 'inherit');

        return '<span style="color:' . esc_attr($color) . ';">' . esc_html($outcome) . '</span>';
    }

    /**
     * @param array<string, mixed> $item
     */
    public function column_partner($item): string
    {
        $partnerId = (int) ($item['partner_id'] ?? 0);

        if ($partnerId === 0) {
            return '—';
        }

        $partner = Services::partners()->find($partnerId);

        return $partner !== null
            ? esc_html($partner->domain)
            : sprintf('#%d', $partnerId);
    }

    /**
     * @param array<string, mixed> $item
     */
    public function column_message($item): string
    {
        $out = esc_html((string) ($item['message'] ?? ''));

        if (is_string($item['endpoint'] ?? null) && $item['endpoint'] !== '') {
            $out .= '<br><code style="font-size:11px;">' . esc_html($item['endpoint']) . '</code>';
        }

        if (is_string($item['context'] ?? null) && $item['context'] !== '' && $item['context'] !== 'null') {
            $out .= '<details style="margin-top:4px;"><summary style="cursor:pointer;" class="description">' . esc_html__('Details', 'openyacht') . '</summary>';
            $out .= '<pre style="margin:4px 0 0;padding:6px 8px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:2px;font-size:11px;overflow-x:auto;max-width:640px;">' . esc_html((string) $item['context']) . '</pre></details>';
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     */
    public function column_default($item, $column_name): string
    {
        return esc_html((string) ($item[$column_name] ?? ''));
    }
}

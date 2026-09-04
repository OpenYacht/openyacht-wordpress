<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\Listing;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Services;

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Standard WP list table of this node's own listings, with the lifecycle
 * transitions the current status allows (ID-8) as row actions.
 */
final class ListingsTable extends \WP_List_Table
{
    public function __construct(private readonly string $type = 'sale')
    {
        parent::__construct([
            'singular' => 'listing',
            'plural' => 'listings',
            'ajax' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox">',
            'thumb' => '',
            'name' => __('Listing', 'openyacht'),
            'status' => __('Status', 'openyacht'),
            'vessel' => __('Vessel', 'openyacht'),
            'price' => __('Price', 'openyacht'),
            'updated' => __('Federation updated', 'openyacht'),
        ];
    }

    /**
     * @param Listing $item
     */
    public function column_cb($item): string
    {
        return sprintf('<input type="checkbox" name="ids[]" value="%d" aria-label="%s">', $item->id, esc_attr($item->name ?? ''));
    }

    /**
     * @param Listing $item
     */
    public function column_thumb($item): string
    {
        foreach (Services::listingMedia()->forListing($item->id) as $media) {
            if ($media->kind === 'profile' && is_string($media->thumbnailUrl)) {
                return '<img src="' . esc_url($media->thumbnailUrl) . '" style="width:60px;height:40px;object-fit:cover;border-radius:2px;" loading="lazy" alt="">';
            }
        }

        return '<span class="dashicons dashicons-format-image" style="color:#c3c4c7;font-size:32px;width:60px;height:40px;"></span>';
    }

    public function prepare_items(): void
    {
        global $wpdb;
        $table = (new \OpenYacht\Schema($wpdb))->tableName('listings');

        $where = [$wpdb->prepare('type = %s', $this->type)];
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';

        if (ListingStatus::tryFrom($status) !== null) {
            $where[] = $wpdb->prepare('status = %s', $status);
        }

        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = $wpdb->prepare('(name LIKE %s OR builder_name LIKE %s OR model_name LIKE %s)', $like, $like, $like);
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);
        $ids = array_map('intval', (array) $wpdb->get_col("SELECT id FROM {$table} {$whereSql} ORDER BY federation_updated_at DESC LIMIT 200"));
        $this->items = array_values(array_filter(array_map(
            static fn (int $id) => Services::listings()->find($id),
            $ids,
        )));
        $this->_column_headers = [$this->get_columns(), [], []];
    }

    /**
     * @param Listing $item
     */
    public function column_name($item): string
    {
        $editUrl = add_query_arg(
            ['page' => ListingsPage::slugFor($item->type), 'action' => 'edit', 'id' => $item->id],
            admin_url('admin.php'),
        );

        $actions = ['edit' => '<a href="' . esc_url($editUrl) . '">' . esc_html__('Edit', 'openyacht') . '</a>'];

        foreach ($item->allowedTransitions() as $target) {
            $actions['transition_' . $target->value] = $this->transitionButton($item, $target);
        }

        return '<strong><a href="' . esc_url($editUrl) . '">' . esc_html($item->name ?? '(unnamed)') . '</a></strong>'
            . '<br><span class="description">' . esc_html($item->uuid) . '</span>'
            . $this->row_actions($actions);
    }

    /**
     * @param Listing $item
     */
    public function column_status($item): string
    {
        $label = esc_html(str_replace('_', ' ', $item->status->value));

        return $item->status === ListingStatus::Active
            ? '<span style="color:#00a32a;font-weight:600;">' . $label . '</span>'
            : $label;
    }

    /**
     * @param Listing $item
     */
    public function column_vessel($item): string
    {
        $parts = array_filter([
            $item->builderName,
            $item->modelName,
            $item->yearBuilt !== null ? (string) $item->yearBuilt : null,
            $item->loaM !== null ? number_format($item->loaM, 1) . 'm' : null,
        ]);

        return esc_html(implode(' · ', $parts));
    }

    /**
     * @param Listing $item
     */
    public function column_price($item): string
    {
        if ($item->priceOnApplication) {
            return esc_html__('POA', 'openyacht');
        }

        if ($item->priceAmount === null || $item->priceCurrency === null) {
            return '—';
        }

        return esc_html($item->priceCurrency . ' ' . number_format((float) $item->priceAmount));
    }

    /**
     * @param Listing $item
     */
    public function column_updated($item): string
    {
        return esc_html($item->federationUpdatedAt ?? '');
    }

    /**
     * @param mixed $item
     */
    public function column_default($item, $column_name): string
    {
        return '';
    }

    private function transitionButton(Listing $listing, ListingStatus $target): string
    {
        $labels = [
            'active' => $listing->status === ListingStatus::Draft ? __('Publish', 'openyacht') : __('Relist', 'openyacht'),
            'under_offer' => __('Under offer', 'openyacht'),
            'sold' => __('Mark sold', 'openyacht'),
            'withdrawn' => __('Withdraw', 'openyacht'),
        ];

        // Nonce GET links, not inline forms: the table now sits inside the
        // bulk-actions form, and forms cannot nest.
        $url = wp_nonce_url(
            add_query_arg(
                ['action' => 'openyacht_listing_transition', 'id' => $listing->id, 'target' => $target->value],
                admin_url('admin-post.php'),
            ),
            'openyacht_listing_transition',
        );

        return '<a href="' . esc_url($url) . '">' . esc_html($labels[$target->value] ?? $target->value) . '</a>';
    }
}

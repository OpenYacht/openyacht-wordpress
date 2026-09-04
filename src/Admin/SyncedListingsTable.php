<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\ListingCopy;
use OpenYacht\Services;

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Partner listings synced into the copies table: preview via the
 * authority-hosted thumbnail (no local image pipeline), select what to
 * import — only selected copies get media cached locally.
 */
final class SyncedListingsTable extends \WP_List_Table
{
    /** @var array<int, string> partner id => domain */
    private array $partnerDomains = [];

    public function __construct(private readonly string $type = 'sale')
    {
        parent::__construct([
            'singular' => 'synced-listing',
            'plural' => 'synced-listings',
            'ajax' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox" />',
            'thumb' => '',
            'name' => __('Listing', 'openyacht'),
            'partner' => __('Partner', 'openyacht'),
            'status' => __('Status', 'openyacht'),
            'price' => __('Price', 'openyacht'),
            'imported' => __('Imported', 'openyacht'),
            'updated' => __('Updated', 'openyacht'),
        ];
    }

    /**
     * @param ListingCopy $item
     */
    public function column_cb($item): string
    {
        return sprintf('<input type="checkbox" name="copy_ids[]" value="%d" aria-label="%s">', $item->id, esc_attr($item->name ?? ''));
    }

    public function prepare_items(): void
    {
        foreach (Services::partners()->all() as $partner) {
            $this->partnerDomains[$partner->id] = $partner->domain;
        }

        $copies = array_filter(
            Services::copies()->active(),
            fn (ListingCopy $copy): bool => $copy->type === $this->type,
        );
        $partnerFilter = isset($_GET['partner']) ? (int) $_GET['partner'] : 0;
        $importFilter = isset($_GET['imported']) ? sanitize_key(wp_unslash($_GET['imported'])) : '';
        $search = isset($_GET['s']) ? strtolower(sanitize_text_field(wp_unslash($_GET['s']))) : '';

        if ($partnerFilter > 0) {
            $copies = array_filter($copies, static fn (ListingCopy $copy): bool => $copy->partnerId === $partnerFilter);
        }

        if ($importFilter === 'yes') {
            $copies = array_filter($copies, static fn (ListingCopy $copy): bool => $copy->isSelected());
        } elseif ($importFilter === 'no') {
            $copies = array_filter($copies, static fn (ListingCopy $copy): bool => ! $copy->isSelected());
        }

        if ($search !== '') {
            $copies = array_filter($copies, static function (ListingCopy $copy) use ($search): bool {
                $builder = (string) ($copy->payload['vessel']['builder']['name'] ?? '');

                return str_contains(strtolower((string) $copy->name), $search)
                    || str_contains(strtolower($builder), $search);
            });
        }

        $copies = array_values($copies);
        $perPage = 30;
        $page = max(1, $this->get_pagenum());
        $this->items = array_slice($copies, ($page - 1) * $perPage, $perPage);
        $this->set_pagination_args(['total_items' => count($copies), 'per_page' => $perPage]);
        $this->_column_headers = [$this->get_columns(), [], []];
    }

    /**
     * @param ListingCopy $item
     */
    public function column_thumb($item): string
    {
        $thumbnail = $item->thumbnailUrl();

        if ($thumbnail === null) {
            return '<span class="dashicons dashicons-format-image" style="color:#c3c4c7;font-size:32px;width:60px;height:40px;"></span>';
        }

        return '<img src="' . esc_url($thumbnail) . '" style="width:60px;height:40px;object-fit:cover;border-radius:2px;" loading="lazy" alt="">';
    }

    /**
     * @param ListingCopy $item
     */
    public function column_name($item): string
    {
        $viewUrl = add_query_arg(
            ['page' => SyncedListingsPage::slugFor($item->type), 'action' => 'view', 'id' => $item->id],
            admin_url('admin.php'),
        );

        $actions = ['view' => '<a href="' . esc_url($viewUrl) . '">' . esc_html__('Preview', 'openyacht') . '</a>'];
        $actions[$item->isSelected() ? 'deselect' : 'select'] = $this->actionButton(
            $item,
            $item->isSelected() ? 'deselect' : 'select',
            $item->isSelected() ? __('Remove from import', 'openyacht') : __('Import', 'openyacht'),
        );

        $vessel = $item->payload['vessel'] ?? [];
        $meta = array_filter([
            $vessel['builder']['name'] ?? null,
            isset($vessel['year_built']) ? (string) $vessel['year_built'] : null,
            isset($vessel['loa_m']) ? number_format((float) $vessel['loa_m'], 1) . 'm' : null,
        ]);

        return '<strong><a href="' . esc_url($viewUrl) . '">' . esc_html($item->name ?? '(unnamed)') . '</a></strong>'
            . '<br><span class="description">' . esc_html(implode(' · ', $meta)) . '</span>'
            . $this->row_actions($actions);
    }

    /**
     * @param ListingCopy $item
     */
    public function column_partner($item): string
    {
        return esc_html($this->partnerDomains[$item->partnerId] ?? $item->authorityDomain);
    }

    /**
     * @param ListingCopy $item
     */
    public function column_status($item): string
    {
        $stale = \OpenYacht\Data::isStale($item)
            ? ' <span style="color:#b32d2e;font-weight:600;">' . esc_html__('stale', 'openyacht') . '</span>'
            : '';

        return esc_html(str_replace('_', ' ', $item->status)) . $stale;
    }

    /**
     * @param ListingCopy $item
     */
    public function column_price($item): string
    {
        $price = $item->payload['listing']['price'] ?? [];

        if (! empty($price['on_application'])) {
            return esc_html__('POA', 'openyacht');
        }

        if (isset($price['amount'], $price['currency'])) {
            return esc_html($price['currency'] . ' ' . number_format((float) $price['amount']));
        }

        // Charter listings have no asking price by design — their pricing
        // is the charter rate block, so show the weekly range instead.
        $rates = $item->payload['charter']['rates'] ?? [];

        if (is_array($rates) && $rates !== []) {
            $mins = array_filter(array_map(static fn (array $rate): ?float => is_numeric($rate['amount_min'] ?? null) ? (float) $rate['amount_min'] : null, $rates));
            $maxs = array_filter(array_map(static fn (array $rate): ?float => is_numeric($rate['amount_max'] ?? null) ? (float) $rate['amount_max'] : null, $rates));
            $currency = (string) ($rates[0]['currency'] ?? '');

            if ($mins !== [] && $currency !== '') {
                $low = number_format(min($mins));
                $high = $maxs !== [] ? number_format(max($maxs)) : null;

                return esc_html($currency . ' ' . ($high !== null && $high !== $low ? "{$low}–{$high}" : $low) . ' ' . __('/ week', 'openyacht'));
            }
        }

        return '—';
    }

    /**
     * @param ListingCopy $item
     */
    public function column_imported($item): string
    {
        if (! $item->isSelected()) {
            return '—';
        }

        $cached = count(Services::copyMedia()->forCopy($item->id));

        return '<span style="color:#00a32a;font-weight:600;">✓</span> '
            . esc_html(sprintf(
                /* translators: %d: number of locally cached images. */
                _n('%d image cached', '%d images cached', $cached, 'openyacht'),
                $cached,
            ));
    }

    /**
     * @param ListingCopy $item
     */
    public function column_updated($item): string
    {
        return esc_html($item->listingUpdatedAt ?? '');
    }

    /**
     * @param mixed $item
     */
    public function column_default($item, $column_name): string
    {
        return '';
    }

    private function actionButton(ListingCopy $copy, string $op, string $label): string
    {
        // Links, not forms: rows sit inside the bulk-action form.
        $url = wp_nonce_url(
            add_query_arg(
                ['action' => 'openyacht_copy_action', 'op' => $op, 'copy_id' => $copy->id],
                admin_url('admin-post.php'),
            ),
            'openyacht_copy_action',
        );

        return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
}

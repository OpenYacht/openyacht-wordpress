<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

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

    public function __construct()
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
            'thumb' => '',
            'name' => __('Listing', 'openyacht'),
            'partner' => __('Partner', 'openyacht'),
            'status' => __('Status', 'openyacht'),
            'price' => __('Price', 'openyacht'),
            'imported' => __('Imported', 'openyacht'),
            'updated' => __('Updated', 'openyacht'),
        ];
    }

    public function prepare_items(): void
    {
        foreach (Services::partners()->all() as $partner) {
            $this->partnerDomains[$partner->id] = $partner->domain;
        }

        $copies = Services::copies()->active();
        $partnerFilter = isset($_GET['partner']) ? (int) $_GET['partner'] : 0;

        if ($partnerFilter > 0) {
            $copies = array_values(array_filter($copies, static fn (ListingCopy $copy): bool => $copy->partnerId === $partnerFilter));
        }

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
            ['page' => SyncedListingsPage::MENU_SLUG, 'action' => 'view', 'id' => $item->id],
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

        if (! isset($price['amount'], $price['currency'])) {
            return '—';
        }

        return esc_html($price['currency'] . ' ' . number_format((float) $price['amount']));
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
        $form = '<form method="post" action="%s" style="display:inline">'
            . '<input type="hidden" name="action" value="openyacht_copy_action">'
            . '<input type="hidden" name="op" value="%s">'
            . '<input type="hidden" name="copy_id" value="%d">'
            . '%s<button type="submit" class="button-link">%s</button></form>';

        return sprintf(
            $form,
            esc_url(admin_url('admin-post.php')),
            esc_attr($op),
            $copy->id,
            wp_nonce_field('openyacht_copy_action', '_wpnonce', true, false),
            esc_html($label),
        );
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

use OpenYacht\Federation\Partner;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Services;

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Standard WP list table of federation partners with the trust actions as
 * row actions.
 */
final class PartnersTable extends \WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'partner',
            'plural' => 'partners',
            'ajax' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'domain' => __('Domain', 'openyacht'),
            'trust' => __('Trust', 'openyacht'),
            'copies' => __('Synced listings', 'openyacht'),
            'last_ok' => __('Last contact', 'openyacht'),
            'health' => __('Health', 'openyacht'),
        ];
    }

    public function prepare_items(): void
    {
        $this->items = Services::partners()->all();
        $this->_column_headers = [$this->get_columns(), [], []];
    }

    /**
     * @param Partner $item
     */
    public function column_domain($item): string
    {
        $actions = [];

        if ($item->trustLevel !== TrustLevel::Verified) {
            $actions['approve'] = $this->actionButton($item, 'approve', __('Approve', 'openyacht'));
        }

        if ($item->trustLevel !== TrustLevel::Blocked) {
            $actions['block'] = $this->actionButton($item, 'block', __('Block', 'openyacht'));
        }

        $actions['refresh'] = $this->actionButton($item, 'refresh', __('Refresh keys', 'openyacht'));

        $pinned = $item->pinnedKeyId !== null
            ? '<br><span class="description">' . esc_html(sprintf(__('Pinned key: %s', 'openyacht'), $item->pinnedKeyId)) . '</span>'
            : '';

        return '<strong>' . esc_html($item->domain) . '</strong>' . $pinned . $this->row_actions($actions);
    }

    /**
     * @param Partner $item
     */
    public function column_trust($item): string
    {
        return esc_html($item->trustLevel->value);
    }

    /**
     * @param Partner $item
     */
    public function column_copies($item): string
    {
        return esc_html((string) Services::copies()->countForPartner($item->id));
    }

    /**
     * @param Partner $item
     */
    public function column_last_ok($item): string
    {
        return esc_html($item->lastOkAt ?? __('never', 'openyacht'));
    }

    /**
     * @param Partner $item
     */
    public function column_health($item): string
    {
        if ($item->isStale()) {
            // FP-15: copies from an unreachable partner are flagged stale.
            return '<span style="color:#b32d2e;font-weight:600;">' . esc_html__('Stale', 'openyacht') . '</span>';
        }

        if ($item->consecutiveFailures > 0) {
            return esc_html(sprintf(
                /* translators: %d: number of consecutive failed sync attempts. */
                __('%d failed attempt(s), backing off', 'openyacht'),
                $item->consecutiveFailures,
            ));
        }

        return esc_html__('OK', 'openyacht');
    }

    /**
     * @param mixed $item
     */
    public function column_default($item, $column_name): string
    {
        return '';
    }

    private function actionButton(Partner $partner, string $op, string $label): string
    {
        $form = '<form method="post" action="%s" style="display:inline">'
            . '<input type="hidden" name="action" value="openyacht_partner">'
            . '<input type="hidden" name="op" value="%s">'
            . '<input type="hidden" name="domain" value="%s">'
            . '%s<button type="submit" class="button-link">%s</button></form>';

        return sprintf(
            $form,
            esc_url(admin_url('admin-post.php')),
            esc_attr($op),
            esc_attr($partner->domain),
            wp_nonce_field('openyacht_partner', '_wpnonce', true, false),
            esc_html($label),
        );
    }
}

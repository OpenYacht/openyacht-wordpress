<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

if (! defined('ABSPATH')) {
    exit;
}

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
            'sharing' => __('Sharing', 'openyacht'),
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
            // The array key becomes the row-action span's CSS class, and
            // core's common.css globally hides `.approve` (comments-list
            // styling) — so the key must not be literally "approve".
            $actions['approve_partner'] = $this->actionButton($item, 'approve', __('Approve', 'openyacht'));
        }

        if ($item->trustLevel !== TrustLevel::Blocked) {
            $actions['sync_now'] = $this->actionButton($item, 'sync_now', __('Sync now', 'openyacht'));
            $actions['block'] = $this->actionButton($item, 'block', __('Block', 'openyacht'));
        }

        $actions['refresh'] = $this->actionButton($item, 'refresh', __('Refresh keys', 'openyacht'));
        $grantsUrl = add_query_arg(
            ['page' => PartnersPage::MENU_SLUG, 'action' => 'grants', 'domain' => $item->domain],
            admin_url('admin.php'),
        );
        $actions['grants'] = '<a href="' . esc_url($grantsUrl) . '">' . esc_html__('Sharing', 'openyacht') . '</a>';

        $pinned = '';

        if ($item->pinnedKeyId !== null) {
            /* translators: %s: the partner's pinned signing key ID */
            $pinned = '<br><span class="description">' . esc_html(sprintf(__('Pinned key: %s', 'openyacht'), $item->pinnedKeyId)) . '</span>';
        }

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
    public function column_sharing($item): string
    {
        $granted = count($item->grantedFieldGroups());
        $total = count(\OpenYacht\Federation\FieldGroup::cases());
        $label = $item->fieldGroups === null
            ? __('All fields', 'openyacht')
            : sprintf(
                /* translators: 1: granted field-group count, 2: total field-group count. */
                __('%1$d of %2$d field groups', 'openyacht'),
                $granted,
                $total,
            );

        if ($item->sharingScope === \OpenYacht\Federation\SharingScope::Curated) {
            $label = __('Curated', 'openyacht') . ' · ' . $label;
        }

        $url = add_query_arg(
            ['page' => PartnersPage::MENU_SLUG, 'action' => 'grants', 'domain' => $item->domain],
            admin_url('admin.php'),
        );

        return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
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

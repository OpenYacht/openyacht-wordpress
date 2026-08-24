<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

use OpenYacht\Services;

/**
 * The federation log as a read-only admin screen: what happened, with
 * whom, and how it went — the first place to look when a partner says
 * "your node isn't answering" or a sync seems stale.
 */
final class ActivityPage
{
    public const MENU_SLUG = 'openyacht-activity';

    public function register(): void
    {
        // After Synced Listings (12), before Settings (20).
        add_action('admin_menu', [$this, 'addMenu'], 15);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            PartnersPage::MENU_SLUG,
            __('Activity', 'openyacht'),
            __('Activity', 'openyacht'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage'],
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('OpenYacht Activity', 'openyacht') . '</h1>';
        echo '<p class="description">' . esc_html__('Every federation event this node records: inbound requests and their verification outcomes, sync passes, partner and key lifecycle, sharing changes. Read-only — request and sync entries are pruned per the retention setting; partner, key, and sharing entries are kept forever.', 'openyacht') . '</p>';

        $this->renderFilters();

        $table = new ActivityTable();
        $table->prepare_items();
        $table->display();
        echo '</div>';
    }

    private function renderFilters(): void
    {
        $filters = ActivityTable::filters();

        echo '<form method="get" style="margin:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '">';

        echo '<select name="channel"><option value="">' . esc_html__('All channels', 'openyacht') . '</option>';

        foreach (Services::logReader()->channels() as $channel) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($channel),
                selected($filters['channel'] ?? '', $channel, false),
                esc_html(ucfirst($channel)),
            );
        }

        echo '</select>';

        echo '<select name="outcome"><option value="">' . esc_html__('All outcomes', 'openyacht') . '</option>';

        foreach (['ok' => __('OK', 'openyacht'), 'error' => __('Error', 'openyacht')] as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr((string) $value),
                selected($filters['outcome'] ?? '', $value, false),
                esc_html((string) $label),
            );
        }

        echo '</select>';

        echo '<select name="partner_id"><option value="">' . esc_html__('All partners', 'openyacht') . '</option>';

        foreach (Services::partners()->all() as $partner) {
            printf(
                '<option value="%d" %s>%s</option>',
                $partner->id,
                selected($filters['partner_id'] ?? 0, $partner->id, false),
                esc_html($partner->domain),
            );
        }

        echo '</select>';
        submit_button(__('Filter', 'openyacht'), '', '', false);
        echo '</form>';
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

use OpenYacht\Data;
use OpenYacht\Federation\ListingCopy;
use OpenYacht\Media\MediaPolicy;
use OpenYacht\Services;

/**
 * Browse and preview partner listings, and pick what to import. Preview
 * imagery is the authority-hosted thumbnail from the wire; importing a
 * copy caches its media locally, removing it purges the cache (usage
 * terms, ID-10).
 */
final class SyncedListingsPage
{
    public const MENU_SLUG = 'openyacht-synced';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 12);
        add_action('admin_post_openyacht_copy_action', [$this, 'handleAction']);
        add_action('admin_notices', [$this, 'notices']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            PartnersPage::MENU_SLUG,
            __('Synced Listings', 'openyacht'),
            __('Synced Listings', 'openyacht'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage'],
        );
    }

    public function handleAction(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage synced listings.', 'openyacht'));
        }

        check_admin_referer('openyacht_copy_action');

        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
        $copy = Services::copies()->find(isset($_POST['copy_id']) ? (int) $_POST['copy_id'] : 0);

        if ($copy === null || ! in_array($op, ['select', 'deselect'], true)) {
            $this->redirect(['openyacht_notice' => 'missing']);
        }

        Services::copies()->setSelected($copy->id, $op === 'select');
        $fresh = Services::copies()->find($copy->id) ?? $copy;

        if ($op === 'select') {
            // Cache its media in the background.
            if (! wp_next_scheduled('openyacht_fetch_copy_media', [$copy->id])) {
                wp_schedule_single_event(time() + 5, 'openyacht_fetch_copy_media', [$copy->id]);
            }
        } elseif (! MediaPolicy::shouldCache($fresh)) {
            Services::mediaService()->expire($fresh);
        }

        $this->redirect(['openyacht_notice' => $op === 'select' ? 'selected' : 'deselected']);
    }

    public function notices(): void
    {
        if (! isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG || ! isset($_GET['openyacht_notice'])) {
            return;
        }

        $messages = [
            'selected' => __('Listing marked for import — its media is being cached in the background.', 'openyacht'),
            'deselected' => __('Listing removed from import; its cached media has been deleted.', 'openyacht'),
            'missing' => __('That synced listing no longer exists.', 'openyacht'),
        ];
        $notice = sanitize_key(wp_unslash($_GET['openyacht_notice']));

        if (isset($messages[$notice])) {
            $class = $notice === 'missing' ? 'notice-error' : 'notice-success';
            printf('<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr($class), esc_html($messages[$notice]));
        }
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if ($action === 'view') {
            $this->renderPreview(Services::copies()->find(isset($_GET['id']) ? (int) $_GET['id'] : 0));
            echo '</div>';

            return;
        }

        echo '<h1>' . esc_html__('Synced Listings', 'openyacht') . '</h1>';
        echo '<p class="description">' . esc_html__('All partner listings sync as data automatically. Mark the ones you want to import — only those get their images cached locally; the rest preview via the partner\'s own thumbnails.', 'openyacht') . '</p>';
        $this->renderPartnerFilter();

        $table = new SyncedListingsTable();
        $table->prepare_items();
        $table->display();
        echo '</div>';
    }

    private function renderPartnerFilter(): void
    {
        $current = isset($_GET['partner']) ? (int) $_GET['partner'] : 0;

        echo '<form method="get" style="margin:8px 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '">';
        echo '<select name="partner" onchange="this.form.submit()">';
        echo '<option value="0">' . esc_html__('All partners', 'openyacht') . '</option>';

        foreach (Services::partners()->all() as $partner) {
            printf(
                '<option value="%d" %s>%s (%d)</option>',
                $partner->id,
                selected($current, $partner->id, false),
                esc_html($partner->domain),
                Services::copies()->countForPartner($partner->id),
            );
        }

        echo '</select></form>';
    }

    private function renderPreview(?ListingCopy $copy): void
    {
        if ($copy === null) {
            echo '<h1>' . esc_html__('Synced listing not found', 'openyacht') . '</h1>';

            return;
        }

        $payload = $copy->payload;
        $partner = Data::partner($copy);
        $backUrl = add_query_arg(['page' => self::MENU_SLUG], admin_url('admin.php'));

        echo '<h1>' . esc_html($copy->name ?? '(unnamed)') . '</h1>';
        echo '<p><a href="' . esc_url($backUrl) . '">&larr; ' . esc_html__('Back to synced listings', 'openyacht') . '</a></p>';

        $thumbnail = $copy->thumbnailUrl();

        if ($thumbnail !== null) {
            echo '<img src="' . esc_url($thumbnail) . '" style="max-width:480px;border-radius:4px;margin-bottom:12px;" alt="">';
        }

        $vessel = $payload['vessel'] ?? [];
        $listing = $payload['listing'] ?? [];
        $specs = $payload['specifications'] ?? [];
        $price = $listing['price'] ?? [];
        $usage = $payload['usage'] ?? [];

        $rows = [
            __('Authority', 'openyacht') => $partner->domain ?? $copy->authorityDomain,
            __('Canonical URI', 'openyacht') => $copy->canonicalUri,
            __('Status', 'openyacht') => str_replace('_', ' ', $copy->status) . ($copy->tombstonedAt !== null ? ' (tombstoned)' : ''),
            __('Builder / model', 'openyacht') => trim(($vessel['builder']['name'] ?? '') . ' ' . ($vessel['model']['name'] ?? '')),
            __('Year / LOA', 'openyacht') => trim(($vessel['year_built'] ?? '') . ' / ' . (isset($vessel['loa_m']) ? $vessel['loa_m'] . 'm' : '')),
            __('Price', 'openyacht') => ! empty($price['on_application']) ? __('POA', 'openyacht') : trim(($price['currency'] ?? '') . ' ' . (isset($price['amount']) ? number_format((float) $price['amount']) : '—')),
            __('Location', 'openyacht') => (string) ($listing['location']['display'] ?? '—'),
            __('Category', 'openyacht') => (string) ($specs['category']['name'] ?? '—'),
            __('Cabins / sleeps', 'openyacht') => trim(($specs['cabins'] ?? '—') . ' / ' . ($specs['sleeps'] ?? '—')),
            __('Gallery', 'openyacht') => sprintf(
                /* translators: %d: number of gallery images on the wire. */
                __('%d images on the wire', 'openyacht'),
                count($payload['media']['gallery'] ?? []),
            ),
            __('Attribution', 'openyacht') => (string) ($usage['attribution_text'] ?? '—'),
            __('Signature verified', 'openyacht') => $copy->signatureVerified ? __('yes', 'openyacht') : __('no', 'openyacht'),
            __('Last change', 'openyacht') => (string) ($copy->listingUpdatedAt ?? '—'),
        ];

        echo '<table class="widefat striped" style="max-width:760px;"><tbody>';

        foreach ($rows as $label => $value) {
            echo '<tr><th style="width:200px;">' . esc_html((string) $label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }

        echo '</tbody></table>';

        $summary = $listing['summary'] ?? null;

        if (is_string($summary) && $summary !== '') {
            echo '<p style="max-width:760px;">' . esc_html($summary) . '</p>';
        }

        echo '<p style="margin-top:12px;">';
        $this->renderToggle($copy);
        echo '</p>';
    }

    private function renderToggle(ListingCopy $copy): void
    {
        printf(
            '<form method="post" action="%s" style="display:inline">'
            . '<input type="hidden" name="action" value="openyacht_copy_action">'
            . '<input type="hidden" name="op" value="%s">'
            . '<input type="hidden" name="copy_id" value="%d">%s'
            . '<button type="submit" class="button button-%s">%s</button></form>',
            esc_url(admin_url('admin-post.php')),
            $copy->isSelected() ? 'deselect' : 'select',
            $copy->id,
            wp_nonce_field('openyacht_copy_action', '_wpnonce', true, false),
            $copy->isSelected() ? 'secondary' : 'primary',
            esc_html($copy->isSelected() ? __('Remove from import', 'openyacht') : __('Import this listing', 'openyacht')),
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private function redirect(array $args): never
    {
        wp_safe_redirect(add_query_arg(['page' => self::MENU_SLUG] + $args, admin_url('admin.php')));
        exit;
    }
}

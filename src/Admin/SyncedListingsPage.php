<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Data;
use OpenYacht\Federation\ListingCopy;
use OpenYacht\Media\MediaPolicy;
use OpenYacht\Services;

/**
 * Browse and preview partner listings, and pick what to import. Preview
 * imagery is the authority-hosted thumbnails from the wire — profile plus
 * any gallery thumbnails (LS-16) — so curation needs no local image
 * pipeline; importing a copy caches its media locally, removing it purges
 * the cache (usage terms, ID-10).
 */
final class SyncedListingsPage
{
    public const MENU_SLUG = 'openyacht-synced';

    /**
     * One page per listing type — sale and charter never mix in one
     * list, mirroring the per-type post types on the display side.
     */
    public function __construct(private readonly string $type = 'sale')
    {
    }

    public static function slugFor(string $type): string
    {
        return $type === 'charter' ? 'openyacht-synced-charter' : self::MENU_SLUG;
    }

    private function slug(): string
    {
        return self::slugFor($this->type);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], $this->type === 'sale' ? 13 : 14);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);

        // The admin-post handler is type-agnostic; one registration.
        if ($this->type === 'sale') {
            add_action('admin_post_openyacht_copy_action', [$this, 'handleAction']);
        }
    }

    /**
     * The configured media-storage driver's human label, for copy that
     * says where cached images live ("Local uploads folder", "Cloudflare R2").
     */
    private function storageLabel(): string
    {
        $name = \OpenYacht\Admin\Settings::get('storage_driver');
        $name = is_string($name) && $name !== '' ? $name : 'local';
        $drivers = \OpenYacht\Media\StorageFactory::drivers();

        return isset($drivers[$name]['label']) ? (string) $drivers[$name]['label'] : $name;
    }

    public function enqueue(string $hook): void
    {
        if (! str_contains($hook, self::MENU_SLUG) && ! str_contains($hook, 'openyacht-synced-charter')) {
            return;
        }

        wp_add_inline_style('common', '.wp-list-table .column-thumb{width:76px}');
        wp_enqueue_script(
            'openyacht-lightbox',
            plugins_url('assets/admin/lightbox.js', OPENYACHT_FILE),
            [],
            OPENYACHT_VERSION,
            true,
        );
    }

    public function addMenu(): void
    {
        add_submenu_page(
            PartnersPage::MENU_SLUG,
            $this->type === 'charter' ? __('Synced Charter', 'openyacht') : __('Synced Sale', 'openyacht'),
            $this->type === 'charter' ? __('Synced Charter', 'openyacht') : __('Synced Sale', 'openyacht'),
            'manage_options',
            $this->slug(),
            [$this, 'renderPage'],
        );
    }

    public function handleAction(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage synced listings.', 'openyacht'));
        }

        check_admin_referer('openyacht_copy_action');

        // Row links arrive via GET; the bulk form posts.
        $request = array_merge($_GET, $_POST);
        $op = isset($request['op']) ? sanitize_key(wp_unslash($request['op'])) : '';

        if (! in_array($op, ['select', 'deselect'], true)) {
            $this->redirect(['openyacht_notice' => 'missing'], 'sale');
        }

        $ids = isset($request['copy_ids']) && is_array($request['copy_ids'])
            ? array_map('intval', $request['copy_ids'])
            : array_filter([isset($request['copy_id']) ? (int) $request['copy_id'] : 0]);
        $redirectType = $ids !== [] ? (Services::copies()->find((int) reset($ids))->type ?? 'sale') : 'sale';
        $applied = 0;

        foreach ($ids as $id) {
            if ($this->apply($id, $op === 'select')) {
                $applied++;
            }
        }

        if ($applied === 0) {
            $this->redirect(['openyacht_notice' => 'missing'], $redirectType);
        }

        $this->redirect([
            'openyacht_notice' => $op === 'select' ? 'selected' : 'deselected',
            'openyacht_count' => $applied,
        ], $redirectType);
    }

    private function apply(int $copyId, bool $select): bool
    {
        $copy = Services::copies()->find($copyId);

        if ($copy === null) {
            return false;
        }

        Services::copies()->setSelected($copy->id, $select);
        $fresh = Services::copies()->find($copy->id) ?? $copy;

        if ($select) {
            // Cache its media in the background.
            if (! wp_next_scheduled('openyacht_fetch_copy_media', [$copy->id])) {
                wp_schedule_single_event(time() + 5, 'openyacht_fetch_copy_media', [$copy->id]);
            }
        } elseif (! MediaPolicy::shouldCache($fresh)) {
            Services::mediaService()->expire($fresh);
        }

        // Public surface: selection gates what display layers project.
        do_action('openyacht_copy_selection_changed', $fresh, $select);

        return true;
    }

    public function notices(): void
    {
        if (! isset($_GET['page']) || $_GET['page'] !== $this->slug() || ! isset($_GET['openyacht_notice'])) {
            return;
        }

        $count = isset($_GET['openyacht_count']) ? max(1, (int) $_GET['openyacht_count']) : 1;
        $messages = [
            'selected' => sprintf(
                /* translators: %d: number of listings. */
                _n('%d listing imported — its images are being cached in the background.', '%d listings imported — their images are being cached in the background.', $count, 'openyacht'),
                $count,
            ),
            'deselected' => sprintf(
                /* translators: %d: number of listings. */
                _n('%d listing removed from import; its cached images were deleted.', '%d listings removed from import; their cached images were deleted.', $count, 'openyacht'),
                $count,
            ),
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

        echo '<h1>' . esc_html($this->type === 'charter' ? __('Synced Charter Listings', 'openyacht') : __('Synced Sale Listings', 'openyacht')) . '</h1>';
        echo '<p class="description">' . esc_html(sprintf(
            /* translators: %s: configured media storage label, e.g. "Cloudflare R2". */
            __('All partner listings sync as data automatically. Import the ones you want on this site — importing caches the images to this site\'s media storage (%s) and hands the listing to your display layer; everything else previews via the partner\'s own thumbnails.', 'openyacht'),
            $this->storageLabel(),
        )) . '</p>';
        $table = new SyncedListingsTable($this->type);
        $table->prepare_items();

        $this->renderFilters();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="openyacht_copy_action">';
        wp_nonce_field('openyacht_copy_action');
        echo '<div class="tablenav top"><div class="alignleft actions bulkactions">';
        echo '<select name="op"><option value="select">' . esc_html__('Import', 'openyacht') . '</option><option value="deselect">' . esc_html__('Remove from import', 'openyacht') . '</option></select> ';
        submit_button(__('Apply', 'openyacht'), 'action', '', false);
        echo '</div></div>';
        $table->display();
        echo '</form>';
        echo '</div>';
    }

    private function renderFilters(): void
    {
        $currentPartner = isset($_GET['partner']) ? (int) $_GET['partner'] : 0;
        $currentImported = isset($_GET['imported']) ? sanitize_key(wp_unslash($_GET['imported'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        echo '<form method="get" style="margin:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($this->slug()) . '">';

        echo '<select name="partner"><option value="0">' . esc_html__('All partners', 'openyacht') . '</option>';

        foreach (Services::partners()->all() as $partner) {
            printf(
                '<option value="%d" %s>%s (%d)</option>',
                $partner->id,
                selected($currentPartner, $partner->id, false),
                esc_html($partner->domain),
                Services::copies()->countForPartner($partner->id),
            );
        }

        echo '</select>';

        echo '<select name="imported">';

        foreach (['' => __('Imported and not', 'openyacht'), 'yes' => __('Imported only', 'openyacht'), 'no' => __('Not imported', 'openyacht')] as $value => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr((string) $value), selected($currentImported, (string) $value, false), esc_html($label));
        }

        echo '</select>';
        echo '<label class="screen-reader-text" for="openyacht-copy-search">' . esc_html__('Search synced listings', 'openyacht') . '</label>';
        echo '<input type="search" id="openyacht-copy-search" name="s" placeholder="' . esc_attr__('Search name or builder…', 'openyacht') . '" value="' . esc_attr($search) . '">';
        submit_button(__('Filter', 'openyacht'), '', '', false);
        echo '</form>';
    }

    private function renderPreview(?ListingCopy $copy): void
    {
        if ($copy === null) {
            echo '<h1>' . esc_html__('Synced listing not found', 'openyacht') . '</h1>';

            return;
        }

        $payload = $copy->payload;
        $partner = Data::partner($copy);
        $backUrl = add_query_arg(['page' => self::slugFor($copy->type)], admin_url('admin.php'));

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
            __('Price', 'openyacht') => $this->priceLine($copy),
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
            echo '<p style="max-width:760px;font-style:italic;">' . esc_html($summary) . '</p>';
        }

        // The full prose lives in descriptions, not the short summary field.
        // Wire content is restricted HTML and consumers MUST sanitise before
        // rendering regardless (LS-5) — the copy stores the payload verbatim.
        $sanitizer = new \OpenYacht\Federation\RichTextSanitizer();

        foreach ((array) ($payload['descriptions'] ?? []) as $description) {
            if (! is_array($description) || ! is_string($description['content'] ?? null) || $description['content'] === '') {
                continue;
            }

            $section = is_string($description['section'] ?? null) ? $description['section'] : '';

            if ($section !== '') {
                echo '<h2 style="margin-top:20px;">' . esc_html(ucfirst(str_replace('_', ' ', $section))) . '</h2>';
            }

            echo '<div style="max-width:760px;">' . wp_kses($sanitizer->sanitize($description['content']), [
                'p' => [], 'br' => [], 'ul' => [], 'ol' => [], 'li' => [],
                'strong' => [], 'em' => [], 'h3' => [], 'h4' => [],
                'a' => ['href' => true, 'rel' => true],
            ]) . '</div>';
        }

        $this->renderCachedImages($copy);
        $this->renderWireGallery($copy);

        echo '<p style="margin-top:12px;">';
        $this->renderToggle($copy);
        echo '</p>';
        echo '<p class="description" style="max-width:760px;">' . esc_html(sprintf(
            /* translators: %s: configured media storage label, e.g. "Cloudflare R2". */
            __('Importing caches every image to this site\'s media storage (%s) — refreshed on partner updates, deleted on tombstones — and exposes the listing to your site\'s display layer via the OpenYacht read API. Removing it deletes the cached images again.', 'openyacht'),
            $this->storageLabel(),
        )) . '</p>';
    }

    private function renderCachedImages(ListingCopy $copy): void
    {
        if (! $copy->isSelected()) {
            return;
        }

        $items = Data::media($copy);

        echo '<h2 style="margin-top:20px;">' . esc_html(sprintf(
            /* translators: %d: number of cached images. */
            __('Cached images (%d)', 'openyacht'),
            count($items),
        )) . '</h2>';

        if ($items === []) {
            echo '<p class="description">' . esc_html__('Images are being cached in the background — check back in a minute, or run "wp openyacht media sync".', 'openyacht') . '</p>';

            return;
        }

        echo '<div style="display:flex;flex-wrap:wrap;gap:8px;max-width:960px;" data-openyacht-gallery>';

        foreach ($items as $item) {
            $thumb = $item['renditions']['w480'] ?? (reset($item['renditions']) ?: null);
            $full = $item['renditions']['w1920'] ?? $item['renditions']['w960'] ?? $thumb;

            if ($thumb === null || $full === null) {
                continue;
            }

            printf(
                '<a href="%s" data-openyacht-lightbox data-caption="%s"><img src="%s" style="width:150px;height:100px;object-fit:cover;border-radius:4px;border:1px solid #c3c4c7;" loading="lazy" alt="%s"></a>',
                esc_url($full['url']),
                esc_attr($item['caption'] ?? ''),
                esc_url($thumb['url']),
                esc_attr($item['caption'] ?? ''),
            );
        }

        echo '</div>';
    }

    /**
     * Pre-import gallery preview from the authority's own gallery
     * thumbnails — the wire addition that lets curation happen before any
     * media is cached. Items without a thumbnail (null on the wire, or
     * pre-amendment payloads) are simply absent; the grid never hotlinks
     * full-resolution images. Selected copies show the cached grid instead.
     */
    private function renderWireGallery(ListingCopy $copy): void
    {
        if ($copy->isSelected()) {
            return;
        }

        $items = $copy->galleryThumbnails();

        if ($items === []) {
            return;
        }

        echo '<h2 style="margin-top:20px;">' . esc_html(sprintf(
            /* translators: %d: number of gallery images with an authority-hosted thumbnail. */
            __('Gallery preview (%d)', 'openyacht'),
            count($items),
        )) . '</h2>';
        echo '<p class="description">' . esc_html__('Previews are served by the partner; importing caches the full-size images to this site.', 'openyacht') . '</p>';
        echo '<div style="display:flex;flex-wrap:wrap;gap:8px;max-width:960px;" data-openyacht-gallery>';

        foreach ($items as $item) {
            $image = sprintf(
                '<img src="%s" style="width:150px;height:100px;object-fit:cover;border-radius:4px;border:1px solid #c3c4c7;" loading="lazy" alt="%s">',
                esc_url($item['thumbnail_url']),
                esc_attr($item['caption'] ?? ''),
            );

            if ($item['url'] !== null) {
                printf(
                    '<a href="%s" data-openyacht-lightbox data-caption="%s">%s</a>',
                    esc_url($item['url']),
                    esc_attr($item['caption'] ?? ''),
                    $image, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts
                );
            } else {
                echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts
            }
        }

        echo '</div>';
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
    /**
     * The preview's price line: the asking price for sale copies, the
     * weekly rate range for charter copies (which have no asking price
     * by design; their pricing is the charter rate block).
     */
    private function priceLine(ListingCopy $copy): string
    {
        $price = $copy->payload['listing']['price'] ?? [];

        if (! empty($price['on_application'])) {
            return __('POA', 'openyacht');
        }

        if (isset($price['amount'], $price['currency'])) {
            return trim($price['currency'] . ' ' . number_format((float) $price['amount']));
        }

        $rates = $copy->payload['charter']['rates'] ?? [];

        if (is_array($rates) && $rates !== []) {
            $mins = array_filter(array_map(static fn (array $rate): ?float => is_numeric($rate['amount_min'] ?? null) ? (float) $rate['amount_min'] : null, $rates));
            $maxs = array_filter(array_map(static fn (array $rate): ?float => is_numeric($rate['amount_max'] ?? null) ? (float) $rate['amount_max'] : null, $rates));
            $currency = (string) ($rates[0]['currency'] ?? '');

            if ($mins !== [] && $currency !== '') {
                $low = number_format(min($mins));
                $high = $maxs !== [] ? number_format(max($maxs)) : null;

                return $currency . ' ' . ($high !== null && $high !== $low ? $low . '–' . $high : $low) . ' ' . __('/ week', 'openyacht');
            }
        }

        return 'EM–';
    }

    /**
     * @param array<string, mixed> $args
     */
    private function redirect(array $args, ?string $type = null): never
    {
        wp_safe_redirect(add_query_arg(['page' => self::slugFor($type ?? $this->type)] + $args, admin_url('admin.php')));
        exit;
    }
}

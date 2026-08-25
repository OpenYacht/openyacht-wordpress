<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

use InvalidArgumentException;
use OpenYacht\Federation\Listing;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Services;

/**
 * Authoring screens for own listings. Every save path goes through
 * IngestService's candidate validation, so nothing invalid ever reaches
 * the tables — the admin form is just another ingest client.
 */
final class ListingsPage
{
    public const MENU_SLUG = 'openyacht-listings';

    /**
     * One page instance per listing type — sale and charter are separate
     * screens, mirroring how post types separate them on the display
     * side. Never mixed in one list.
     */
    public function __construct(private readonly string $type = 'sale')
    {
    }

    public static function slugFor(string $type): string
    {
        return $type === 'charter' ? 'openyacht-charter-listings' : self::MENU_SLUG;
    }

    private function slug(): string
    {
        return self::slugFor($this->type);
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], $this->type === 'sale' ? 11 : 12);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);

        // The admin-post handlers are type-agnostic; one registration.
        if ($this->type === 'sale') {
            add_action('admin_post_openyacht_listing_save', [$this, 'handleSave']);
            add_action('admin_post_openyacht_listing_transition', [$this, 'handleTransition']);
            add_action('admin_post_openyacht_listing_bulk', [$this, 'handleBulk']);
        }
    }

    public function addMenu(): void
    {
        add_submenu_page(
            PartnersPage::MENU_SLUG,
            $this->type === 'charter' ? __('Charter Listings', 'openyacht') : __('Sale Listings', 'openyacht'),
            $this->type === 'charter' ? __('Charter Listings', 'openyacht') : __('Sale Listings', 'openyacht'),
            'manage_options',
            $this->slug(),
            [$this, 'renderPage'],
        );
    }

    public function enqueue(string $hook): void
    {
        if (! str_contains($hook, $this->slug())) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if ($action !== 'new' && $action !== 'edit') {
            // List view: native WP styling plus a width for the thumbnail
            // column. The editor bundle must NOT load here — Tailwind's
            // .fixed utility collides with WP's `fixed` class on
            // .wp-list-table and position:fixes the whole table.
            wp_add_inline_style('common', '.wp-list-table .column-thumb{width:76px}');

            return;
        }

        wp_enqueue_media();
        wp_enqueue_editor();
        wp_enqueue_script(
            'openyacht-listing-media',
            plugins_url('assets/admin/listing-media.js', OPENYACHT_FILE),
            ['jquery'],
            OPENYACHT_VERSION,
            true,
        );
        wp_enqueue_style(
            'openyacht-leaflet',
            plugins_url('assets/vendor/leaflet/leaflet.css', OPENYACHT_FILE),
            [],
            '1.9.4',
        );
        wp_enqueue_script(
            'openyacht-leaflet',
            plugins_url('assets/vendor/leaflet/leaflet.js', OPENYACHT_FILE),
            [],
            '1.9.4',
            true,
        );
        wp_enqueue_style(
            'openyacht-editor',
            plugins_url('assets/admin/editor.css', OPENYACHT_FILE),
            ['openyacht-leaflet'],
            OPENYACHT_VERSION,
        );
        wp_enqueue_script(
            'openyacht-editor',
            plugins_url('assets/admin/editor.js', OPENYACHT_FILE),
            ['openyacht-leaflet'],
            OPENYACHT_VERSION,
            true,
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        echo '<div class="wrap">';

        if ($action === 'new' || $action === 'edit') {
            $listing = null;

            if ($action === 'edit') {
                $listing = Services::listings()->find(isset($_GET['id']) ? (int) $_GET['id'] : 0);

                if ($listing === null) {
                    echo '<h1>' . esc_html__('Listing not found', 'openyacht') . '</h1></div>';

                    return;
                }
            }

            $this->renderEditor($listing);
        } else {
            $this->renderList();
        }

        echo '</div>';
    }

    private function renderList(): void
    {
        $newUrl = add_query_arg(['page' => $this->slug(), 'action' => 'new', 'listing_type' => $this->type], admin_url('admin.php'));

        $heading = $this->type === 'charter' ? __('Charter Listings', 'openyacht') : __('Sale Listings', 'openyacht');
        echo '<h1 class="wp-heading-inline">' . esc_html($heading) . '</h1> ';
        echo '<a href="' . esc_url($newUrl) . '" class="page-title-action">' . esc_html__('Add New', 'openyacht') . '</a>';
        echo '<hr class="wp-header-end">';

        $table = new ListingsTable($this->type);
        $table->prepare_items();

        $this->renderFilters();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="openyacht_listing_bulk">';
        wp_nonce_field('openyacht_listing_bulk');
        echo '<div class="tablenav top"><div class="alignleft actions bulkactions">';
        echo '<select name="target">';

        foreach ([
            'active' => __('Publish / relist', 'openyacht'),
            'under_offer' => __('Mark under offer', 'openyacht'),
            'sold' => __('Mark sold', 'openyacht'),
            'withdrawn' => __('Withdraw', 'openyacht'),
        ] as $value => $label) {
            printf('<option value="%s">%s</option>', esc_attr($value), esc_html($label));
        }

        echo '</select> ';
        submit_button(__('Apply', 'openyacht'), 'action', '', false);
        echo '</div></div>';
        $table->display();
        echo '</form>';
    }

    private function renderFilters(): void
    {
        $currentStatus = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        echo '<form method="get" style="margin:8px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($this->slug()) . '">';
        echo '<select name="status"><option value="">' . esc_html__('All statuses', 'openyacht') . '</option>';

        foreach (ListingStatus::cases() as $status) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($status->value),
                selected($currentStatus, $status->value, false),
                esc_html(ucfirst(str_replace('_', ' ', $status->value))),
            );
        }

        echo '</select>';
        echo '<input type="search" name="s" placeholder="' . esc_attr__('Search name, builder or model…', 'openyacht') . '" value="' . esc_attr($search) . '">';
        submit_button(__('Filter', 'openyacht'), '', '', false);
        echo '</form>';
    }

    private function renderEditor(?Listing $listing): void
    {
        // The custom editor carries its own title in the rail; a
        // screen-reader h1 + wp-header-end keeps admin notices anchored.
        $title = $listing === null
            ? __('Add Listing', 'openyacht')
            : sprintf(
                /* translators: %s: listing name. */
                __('Edit listing: %s', 'openyacht'),
                $listing->name ?? '(unnamed)',
            );

        echo '<h1 class="screen-reader-text">' . esc_html($title) . '</h1>';
        echo '<hr class="wp-header-end" style="border:0;margin:0;">';

        (new ListingForm())->render($listing, $this->oldInput());
    }

    public function handleSave(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage OpenYacht listings.', 'openyacht'));
        }

        check_admin_referer('openyacht_listing_save');

        $input = isset($_POST['oy']) && is_array($_POST['oy']) ? wp_unslash($_POST['oy']) : [];
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $form = new ListingForm();
        $columns = $form->columnsFromInput($input);
        $mediaRows = $form->mediaRowsFromInput($input);
        // Alt text lives on the attachment, not the listing — apply it even
        // when validation sends the form back, so the work is never lost.
        $form->updateAttachmentAlts($input);

        $listingType = $columns['type'] ?? 'sale';

        if ($id > 0) {
            $listing = Services::listings()->find($id);

            if ($listing === null) {
                $this->redirect(['openyacht_notice' => 'missing']);
            }

            $listingType = $listing->type;
            $result = Services::ingest()->reviseFromColumns($listing, $columns, $mediaRows);
        } else {
            // New listings are created as drafts; publishing is an explicit
            // lifecycle transition from the list screen (ID-8).
            $result = Services::ingest()->createFromColumns($columns, $mediaRows, ListingStatus::Draft);
        }

        if ($result instanceof \WP_Error) {
            set_transient($this->transientKey('errors'), (array) $result->get_error_data(), 120);
            set_transient($this->transientKey('input'), $input, 120);

            $this->redirect(array_filter([
                'action' => $id > 0 ? 'edit' : 'new',
                'id' => $id > 0 ? $id : null,
                'listing_type' => $id > 0 ? null : $listingType,
                'openyacht_notice' => 'invalid',
            ]), $listingType);
        }

        // Audience control: transitions are recorded per partner, so
        // unsharing tombstones and re-sharing resurfaces on the next poll.
        $audience = \OpenYacht\Federation\Audience::tryFrom(
            isset($input['audience']) ? sanitize_key((string) $input['audience']) : 'everyone',
        ) ?? \OpenYacht\Federation\Audience::Everyone;
        $selectedPartners = array_map('intval', (array) ($input['audience_partners'] ?? []));
        $selectedGroups = array_map('intval', (array) ($input['audience_groups'] ?? []));
        Services::sharingService()->setAudience($result, $audience, $selectedPartners, $selectedGroups);

        $this->redirect(['openyacht_notice' => $id > 0 ? 'saved' : 'created'], $listingType);
    }

    public function handleTransition(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage OpenYacht listings.', 'openyacht'));
        }

        check_admin_referer('openyacht_listing_transition');

        // Row actions arrive as nonce GET links (they sit inside the bulk
        // form, where a nested form is invalid HTML).
        $request = array_merge($_GET, $_POST);
        $listing = Services::listings()->find(isset($request['id']) ? (int) $request['id'] : 0);
        $target = ListingStatus::tryFrom(isset($request['target']) ? sanitize_key(wp_unslash($request['target'])) : '');

        if ($listing === null || $target === null) {
            $this->redirect(['openyacht_notice' => 'missing']);
        }

        try {
            Services::listingService()->transition($listing, $target);
        } catch (InvalidArgumentException $exception) {
            set_transient($this->transientKey('errors'), [$exception->getMessage()], 120);
            $this->redirect(['openyacht_notice' => 'invalid_transition'], $listing->type);
        }

        $this->redirect(['openyacht_notice' => 'transitioned'], $listing->type);
    }

    /**
     * Bulk status changes run each listing through the same lifecycle
     * transition as the row actions, so tombstones and feed updates flow
     * exactly as they would one at a time. Disallowed transitions are
     * skipped and counted, never forced.
     */
    public function handleBulk(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage OpenYacht listings.', 'openyacht'));
        }

        check_admin_referer('openyacht_listing_bulk');

        $target = ListingStatus::tryFrom(isset($_POST['target']) ? sanitize_key(wp_unslash($_POST['target'])) : '');
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $bulkType = $ids !== [] ? (Services::listings()->find($ids[0])->type ?? 'sale') : 'sale';

        if ($target === null || $ids === []) {
            $this->redirect(['openyacht_notice' => 'bulk_none'], $bulkType);
        }

        $applied = 0;
        $skipped = 0;

        foreach ($ids as $id) {
            $listing = Services::listings()->find($id);

            if ($listing === null || ! in_array($target, $listing->allowedTransitions(), true)) {
                $skipped++;

                continue;
            }

            Services::listingService()->transition($listing, $target);
            $applied++;
        }

        $this->redirect(['openyacht_notice' => 'bulk_transitioned', 'applied' => $applied, 'skipped' => $skipped], $bulkType);
    }

    public function notices(): void
    {
        if (! isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG || ! isset($_GET['openyacht_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['openyacht_notice']));

        $success = [
            'created' => __('Listing created as a draft. Publish it from the list when it is ready — drafts are never distributed.', 'openyacht'),
            'saved' => __('Listing saved. Partners receive the change on their next poll.', 'openyacht'),
            'transitioned' => __('Status updated. Partners receive the change (or a tombstone) on their next poll.', 'openyacht'),
            'bulk_transitioned' => sprintf(
                /* translators: 1: number updated, 2: number skipped. */
                __('%1$d listing(s) updated, %2$d skipped (the change was not allowed from their current status). Partners receive changes on their next poll.', 'openyacht'),
                isset($_GET['applied']) ? (int) $_GET['applied'] : 0,
                isset($_GET['skipped']) ? (int) $_GET['skipped'] : 0,
            ),
        ];

        if (isset($success[$notice])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($success[$notice]));

            return;
        }

        $errors = get_transient($this->transientKey('errors'));
        delete_transient($this->transientKey('errors'));

        $intro = match ($notice) {
            'invalid' => __('The listing failed validation and was not saved:', 'openyacht'),
            'invalid_transition' => __('That status change is not allowed:', 'openyacht'),
            'missing' => __('That listing no longer exists.', 'openyacht'),
            'bulk_none' => __('Nothing to do — select at least one listing and a status.', 'openyacht'),
            default => null,
        };

        if ($intro === null) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>' . esc_html($intro) . '</strong></p>';

        if (is_array($errors) && $errors !== []) {
            echo '<ul style="list-style:disc;margin-left:2em;">';

            foreach (array_slice($errors, 0, 10) as $error) {
                echo '<li>' . esc_html((string) $error) . '</li>';
            }

            echo '</ul>';
        }

        echo '</div>';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function oldInput(): ?array
    {
        if (! isset($_GET['openyacht_notice']) || $_GET['openyacht_notice'] !== 'invalid') {
            return null;
        }

        $input = get_transient($this->transientKey('input'));
        delete_transient($this->transientKey('input'));

        return is_array($input) ? $input : null;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function redirect(array $args, ?string $type = null): never
    {
        wp_safe_redirect(add_query_arg(['page' => self::slugFor($type ?? $this->type)] + $args, admin_url('admin.php')));
        exit;
    }

    private function transientKey(string $kind): string
    {
        return 'openyacht_listing_' . $kind . '_' . get_current_user_id();
    }
}

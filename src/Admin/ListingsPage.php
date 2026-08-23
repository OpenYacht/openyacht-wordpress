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

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 11);
        add_action('admin_post_openyacht_listing_save', [$this, 'handleSave']);
        add_action('admin_post_openyacht_listing_transition', [$this, 'handleTransition']);
        add_action('admin_notices', [$this, 'notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            PartnersPage::MENU_SLUG,
            __('Listings', 'openyacht'),
            __('Listings', 'openyacht'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage'],
        );
    }

    public function enqueue(string $hook): void
    {
        if (! str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_media();
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
        $newUrl = add_query_arg(['page' => self::MENU_SLUG, 'action' => 'new'], admin_url('admin.php'));

        echo '<h1 class="wp-heading-inline">' . esc_html__('OpenYacht Listings', 'openyacht') . '</h1> ';
        echo '<a href="' . esc_url($newUrl) . '" class="page-title-action">' . esc_html__('Add New', 'openyacht') . '</a>';
        echo '<hr class="wp-header-end">';

        $table = new ListingsTable();
        $table->prepare_items();
        $table->display();
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

        if ($id > 0) {
            $listing = Services::listings()->find($id);

            if ($listing === null) {
                $this->redirect(['openyacht_notice' => 'missing']);
            }

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
                'openyacht_notice' => 'invalid',
            ]));
        }

        // Audience control: transitions are recorded per partner, so
        // unsharing tombstones and re-sharing resurfaces on the next poll.
        $audience = \OpenYacht\Federation\Audience::tryFrom(
            isset($input['audience']) ? sanitize_key((string) $input['audience']) : 'everyone',
        ) ?? \OpenYacht\Federation\Audience::Everyone;
        $selectedPartners = array_map('intval', (array) ($input['audience_partners'] ?? []));
        Services::sharingService()->setAudience($result, $audience, $selectedPartners);

        $this->redirect(['openyacht_notice' => $id > 0 ? 'saved' : 'created']);
    }

    public function handleTransition(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage OpenYacht listings.', 'openyacht'));
        }

        check_admin_referer('openyacht_listing_transition');

        $listing = Services::listings()->find(isset($_POST['id']) ? (int) $_POST['id'] : 0);
        $target = ListingStatus::tryFrom(isset($_POST['target']) ? sanitize_key(wp_unslash($_POST['target'])) : '');

        if ($listing === null || $target === null) {
            $this->redirect(['openyacht_notice' => 'missing']);
        }

        try {
            Services::listingService()->transition($listing, $target);
        } catch (InvalidArgumentException $exception) {
            set_transient($this->transientKey('errors'), [$exception->getMessage()], 120);
            $this->redirect(['openyacht_notice' => 'invalid_transition']);
        }

        $this->redirect(['openyacht_notice' => 'transitioned']);
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
    private function redirect(array $args): never
    {
        wp_safe_redirect(add_query_arg(['page' => self::MENU_SLUG] + $args, admin_url('admin.php')));
        exit;
    }

    private function transientKey(string $kind): string
    {
        return 'openyacht_listing_' . $kind . '_' . get_current_user_id();
    }
}

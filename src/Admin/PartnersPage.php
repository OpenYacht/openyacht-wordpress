<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

use OpenYacht\Services;
use Throwable;

/**
 * Partner management in wp-admin: the trust decisions (approve/block) are
 * deliberately human ones (FP-13), so they live on a screen, not just in
 * the CLI. Native WordPress admin styling — this is familiar chrome, not
 * a custom surface.
 */
final class PartnersPage
{
    public const MENU_SLUG = 'openyacht-partners';

    /**
     * The OpenYacht menu mark: a minimal yacht (jib, mainsail, hull),
     * drawn to stay legible at the sidebar's 20px. Fill is the admin
     * menu's resting gray — the display addon carries the same mark for
     * its post type menus; keep the two in sync when it changes.
     */
    private const MENU_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a7aaad" d="M10.8 2.2V13.2H3.4ZM13 4.8V13.2H19.6ZM2.6 15.4H21.4L18.2 19.6H5.8Z"/></svg>';

    public static function menuIcon(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode(self::MENU_ICON_SVG);
    }

    private const ACTION = 'openyacht_partner';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_post_' . self::ACTION, [$this, 'handleAction']);
        add_action('admin_notices', [$this, 'notices']);
    }

    public function addMenu(): void
    {
        add_menu_page(
            __('OpenYacht', 'openyacht'),
            __('OpenYacht', 'openyacht'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage'],
            self::menuIcon(),
            58,
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Partners', 'openyacht'),
            __('Partners', 'openyacht'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'renderPage'],
        );
    }

    public function handleAction(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage OpenYacht partners.', 'openyacht'));
        }

        check_admin_referer(self::ACTION);

        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
        $domain = isset($_POST['domain']) ? strtolower(sanitize_text_field(wp_unslash($_POST['domain']))) : '';
        $service = Services::partnerService();
        $notice = 'error';
        $syncCounts = [];

        try {
            switch ($op) {
                case 'add':
                    $publicKey = isset($_POST['public_key']) ? trim(sanitize_text_field(wp_unslash($_POST['public_key']))) : '';
                    $publicKey === ''
                        ? $service->add($domain)
                        : $service->addWithKey($domain, $publicKey);
                    $notice = 'added';
                    break;
                case 'grants':
                    $partner = Services::partners()->findByDomain($domain);

                    if ($partner === null) {
                        break;
                    }

                    $groups = array_values(array_filter(array_map(
                        static fn ($value) => \OpenYacht\Federation\FieldGroup::tryFrom(sanitize_key((string) $value))?->value,
                        isset($_POST['groups']) && is_array($_POST['groups']) ? wp_unslash($_POST['groups']) : [],
                    )));
                    // null = every group granted; an explicit list restricts.
                    Services::partners()->update($partner->id, [
                        'field_groups' => count($groups) === count(\OpenYacht\Federation\FieldGroup::cases()) ? null : $groups,
                    ]);
                    Services::logger()->log('partner', "Field-group grants for {$partner->domain} set to [" . implode(', ', $groups) . ']', 'grants_changed', $partner->id);
                    // The partner's whole served view changed: resend it on
                    // their next poll rather than waiting for content churn.
                    Services::sharingService()->refreshPartnerFeed($partner->id, "grants changed for {$partner->domain}");
                    $notice = 'grants';
                    break;
                case 'scope':
                    $partner = Services::partners()->findByDomain($domain);
                    $scope = \OpenYacht\Federation\SharingScope::tryFrom(
                        isset($_POST['sharing_scope']) ? sanitize_key(wp_unslash($_POST['sharing_scope'])) : '',
                    );

                    if ($partner === null || $scope === null) {
                        break;
                    }

                    // The flip replays through the visibility event log:
                    // standard -> curated tombstones everyone-only listings,
                    // curated -> standard resurfaces them. No wire timestamp
                    // moves.
                    Services::sharingService()->setSharingScope($partner, $scope);
                    $notice = 'scope_saved';
                    break;
                case 'shares':
                    $partner = Services::partners()->findByDomain($domain);
                    $type = isset($_POST['share_type']) ? sanitize_key(wp_unslash($_POST['share_type'])) : '';

                    if ($partner === null || ! in_array($type, ['sale', 'charter'], true)) {
                        break;
                    }

                    $listingIds = array_map('intval', isset($_POST['listing_ids']) && is_array($_POST['listing_ids']) ? wp_unslash($_POST['listing_ids']) : []);
                    // Each picker submits ONE type's full direct-share list;
                    // the replace is scoped to that type, so saving the sale
                    // picker can never unshare charter listings it never
                    // showed (and vice versa).
                    Services::sharingService()->replaceDirectShares($partner, $type, $listingIds);
                    $notice = 'shares_saved';
                    break;
                case 'directory_refresh':
                    $count = Services::nodeDirectoryIndex()->refresh();
                    Services::logger()->log('partner', "Node directory refreshed: {$count} entries", 'directory_refreshed');
                    $notice = 'directory_refreshed';
                    break;
                case 'group_create':
                    $name = isset($_POST['group_name']) ? trim(sanitize_text_field(wp_unslash($_POST['group_name']))) : '';

                    if ($name !== '') {
                        Services::partnerGroups()->create($name);
                        Services::logger()->log('partner', "Partner group \"{$name}\" created", 'group_created');
                        $notice = 'group_saved';
                    }
                    break;
                case 'group_save':
                    $groupId = isset($_POST['group_id']) ? (int) $_POST['group_id'] : 0;
                    $group = Services::partnerGroups()->find($groupId);

                    if ($group === null) {
                        break;
                    }

                    if (! empty($_POST['delete_group'])) {
                        Services::sharingService()->deleteGroup($group->id);
                        $notice = 'group_deleted';
                        break;
                    }

                    $name = isset($_POST['group_name']) ? trim(sanitize_text_field(wp_unslash($_POST['group_name']))) : '';

                    if ($name !== '' && $name !== $group->name) {
                        Services::partnerGroups()->rename($group->id, $name);
                    }

                    $memberIds = array_map('intval', isset($_POST['members']) && is_array($_POST['members']) ? $_POST['members'] : []);
                    // Membership changes replay through the visibility-event
                    // log: joined partners pick up every listing selecting
                    // this group on their next poll, removed partners get
                    // tombstones — no listing is touched.
                    $result = Services::sharingService()->replaceGroupMembers($group->id, $memberIds);
                    Services::logger()->log('partner', "Partner group \"{$group->name}\" saved: {$result['revealed']} pair(s) gain visibility, {$result['hidden']} lose it", 'group_saved');
                    $notice = 'group_saved';
                    break;
                case 'sync_now':
                    $partner = Services::partners()->findByDomain($domain);

                    if ($partner === null || $partner->trustLevel === \OpenYacht\Federation\TrustLevel::Blocked) {
                        break;
                    }

                    // Deliberate human click: no backoff gate — asking to
                    // retry a failing partner right now is exactly the
                    // point. The cron watchdog's last-run option is left
                    // alone, so a single manual sync can't mask a sleeping
                    // wp-cron.
                    $result = Services::syncService()->sync($partner);
                    Services::logger()->log(
                        'sync',
                        "Synced {$partner->domain}: {$result->created} created, {$result->updated} updated, {$result->tombstoned} tombstoned",
                        'ok',
                        $partner->id,
                    );
                    $syncCounts = [
                        'oy_created' => $result->created,
                        'oy_updated' => $result->updated,
                        'oy_tombstoned' => $result->tombstoned,
                    ];
                    $notice = 'synced';
                    break;
                case 'approve':
                case 'block':
                case 'refresh':
                    $partner = Services::partners()->findByDomain($domain);

                    if ($partner === null) {
                        break;
                    }

                    match ($op) {
                        'approve' => $service->approve($partner, get_current_user_id() > 0 ? get_current_user_id() : null),
                        'block' => $service->block($partner),
                        'refresh' => $service->refreshKeys($partner, confirmPin: true),
                    };
                    $notice = $op === 'refresh' ? 'refreshed' : ($op === 'approve' ? 'approved' : 'blocked');
                    break;
            }
        } catch (Throwable $exception) {
            set_transient('openyacht_partner_error_' . get_current_user_id(), $exception->getMessage(), 60);
        }

        // Approval is the moment the partner starts receiving listings, so
        // land on its sharing screen — grants default to everything, and
        // this is where that decision should be looked at, not discovered.
        // Scope and shared-listings saves land back there too: they are
        // that screen's own forms.
        $args = in_array($notice, ['approved', 'scope_saved', 'shares_saved'], true)
            ? ['page' => self::MENU_SLUG, 'action' => 'grants', 'domain' => $domain, 'openyacht_notice' => $notice]
            : ['page' => self::MENU_SLUG, 'openyacht_notice' => $notice] + $syncCounts;

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function notices(): void
    {
        if (! isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG || ! isset($_GET['openyacht_notice'])) {
            return;
        }

        $notice = sanitize_key(wp_unslash($_GET['openyacht_notice']));

        if ($notice === 'error') {
            $message = get_transient('openyacht_partner_error_' . get_current_user_id());
            delete_transient('openyacht_partner_error_' . get_current_user_id());
            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                esc_html(is_string($message) && $message !== '' ? $message : __('The partner action failed.', 'openyacht')),
            );

            return;
        }

        if ($notice === 'synced') {
            printf(
                '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                esc_html(sprintf(
                    /* translators: 1: created count, 2: updated count, 3: tombstoned count. */
                    __('Sync complete: %1$d created, %2$d updated, %3$d tombstoned.', 'openyacht'),
                    isset($_GET['oy_created']) ? (int) $_GET['oy_created'] : 0,
                    isset($_GET['oy_updated']) ? (int) $_GET['oy_updated'] : 0,
                    isset($_GET['oy_tombstoned']) ? (int) $_GET['oy_tombstoned'] : 0,
                )),
            );

            return;
        }

        $messages = [
            'added' => __('Partner added (provisional). Approve it to start receiving requests as a verified partner.', 'openyacht'),
            'approved' => __('Partner approved — it starts receiving listings on its next poll. Review below what it gets: every field group is granted by default.', 'openyacht'),
            'blocked' => __('Partner blocked. All its requests will be rejected.', 'openyacht'),
            'refreshed' => __('Partner keys refreshed.', 'openyacht'),
            'grants' => __('Sharing rules saved. They apply to every payload this partner receives from now on.', 'openyacht'),
            'group_saved' => __('Group saved. Joined partners pick up its listings on their next poll; removed partners receive tombstones.', 'openyacht'),
            'directory_refreshed' => __('Node directory refreshed from openyacht.org.', 'openyacht'),
            'group_deleted' => __('Group deleted. Listings that shared only through it were tombstoned for its members.', 'openyacht'),
            'scope_saved' => __('Sharing scope saved. Visibility changes reach the partner on its next poll — removed listings tombstone, added ones surface.', 'openyacht'),
            'shares_saved' => __('Shared listings saved. Changes reach the partner on its next poll.', 'openyacht'),
        ];

        if (isset($messages[$notice])) {
            printf('<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html($messages[$notice]));
        }
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap">';

        $action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';

        if ($action === 'grants') {
            $this->renderGrantsForm(isset($_GET['domain']) ? sanitize_text_field(wp_unslash($_GET['domain'])) : '');
            echo '</div>';

            return;
        }

        echo '<h1>' . esc_html__('OpenYacht Partners', 'openyacht') . '</h1>';
        $table = new PartnersTable();
        $table->prepare_items();
        $table->display();
        $this->renderAddForm();
        $this->renderDirectory();
        $this->renderGroups();
        echo '</div>';
    }

    /**
     * Find partners: the project's advisory node directory, refreshed only
     * from the canonical URL. Presence conveys existence, nothing more —
     * adding a node here runs the exact same TOFU well-known verification
     * as typing its domain by hand.
     */
    private function renderDirectory(): void
    {
        $index = Services::nodeDirectoryIndex();
        $entries = $index->entries();
        $partnered = [];

        foreach (Services::partners()->all() as $partner) {
            $partnered[$partner->domain] = true;
        }

        $ownDomain = \OpenYacht\Federation\NodeConfig::identityDomain();

        echo '<hr style="margin:2em 0;">';
        echo '<h2>' . esc_html__('Find partners', 'openyacht') . '</h2>';
        echo '<p class="description" style="max-width:640px;">' . esc_html__('The OpenYacht node directory is an opt-in phonebook of nodes that asked to be listed. An entry is not an endorsement and proves nothing — adding a node from here verifies it from its own domain, exactly like typing the domain by hand.', 'openyacht') . '</p>';

        if ($entries === []) {
            echo '<p>' . esc_html__('The directory is currently empty — the network is young, and listing is optional in both directions.', 'openyacht') . '</p>';
        } else {
            echo '<table class="widefat striped" style="max-width:820px;"><thead><tr>';
            echo '<th>' . esc_html__('Brokerage', 'openyacht') . '</th><th>' . esc_html__('Node domain', 'openyacht') . '</th><th>' . esc_html__('Country', 'openyacht') . '</th><th>' . esc_html__('Listed', 'openyacht') . '</th><th></th>';
            echo '</tr></thead><tbody>';

            foreach ($entries as $entry) {
                echo '<tr>';
                echo '<td><a href="' . esc_url($entry['website']) . '" target="_blank" rel="noopener">' . esc_html($entry['name']) . '</a></td>';
                echo '<td><code>' . esc_html($entry['domain']) . '</code></td>';
                echo '<td>' . esc_html($entry['country']) . '</td>';
                echo '<td>' . esc_html($entry['listed_at']) . '</td>';
                echo '<td>';

                if ($entry['domain'] === $ownDomain) {
                    echo '<em>' . esc_html__('This node', 'openyacht') . '</em>';
                } elseif (isset($partnered[$entry['domain']])) {
                    echo '<em>' . esc_html__('Already a partner', 'openyacht') . '</em>';
                } else {
                    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                    echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
                    echo '<input type="hidden" name="op" value="add">';
                    echo '<input type="hidden" name="domain" value="' . esc_attr($entry['domain']) . '">';
                    wp_nonce_field(self::ACTION);
                    submit_button(__('Add as partner', 'openyacht'), 'small', '', false);
                    echo '</form>';
                }

                echo '</td></tr>';
            }

            echo '</tbody></table>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px;display:flex;gap:8px;align-items:center;">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="op" value="directory_refresh">';
        wp_nonce_field(self::ACTION);
        submit_button(__('Refresh from openyacht.org', 'openyacht'), 'secondary', '', false);

        if ($index->fetchedAt() !== null) {
            echo '<span class="description">' . esc_html(sprintf(
                /* translators: %s: UTC datetime. */
                __('Last refreshed %s UTC.', 'openyacht'),
                $index->fetchedAt(),
            )) . '</span>';
        } else {
            echo '<span class="description">' . esc_html__('Showing the copy bundled with the plugin.', 'openyacht') . '</span>';
        }

        echo '</form>';
    }

    /**
     * Node-defined partner groups (company sites, offices, third parties,
     * …): audience shorthand for the listing editor. Membership changes
     * replay through the visibility-event log immediately.
     */
    private function renderGroups(): void
    {
        $groups = Services::partnerGroups();
        $partners = Services::partners()->all();

        echo '<hr style="margin:2em 0;">';
        echo '<h2>' . esc_html__('Partner groups', 'openyacht') . '</h2>';
        echo '<p class="description">' . esc_html__('Group partners however this brokerage thinks about them — company sites, offices, third parties. Listings can share with a group instead of picking partners one by one; adding a partner to a group immediately shares every listing that selects it, and removing one sends tombstones.', 'openyacht') . '</p>';

        foreach ($groups->all() as $group) {
            $members = $groups->members($group->id);

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;padding:12px;border:1px solid #c3c4c7;background:#fff;max-width:640px;">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
            echo '<input type="hidden" name="op" value="group_save">';
            echo '<input type="hidden" name="group_id" value="' . (int) $group->id . '">';
            wp_nonce_field(self::ACTION);
            echo '<p><input type="text" name="group_name" value="' . esc_attr($group->name) . '" class="regular-text" aria-label="' . esc_attr__('Group name', 'openyacht') . '"> <span class="description">' . esc_html(sprintf(_n('%d member', '%d members', count($members), 'openyacht'), count($members))) . '</span></p>';
            echo '<fieldset style="display:flex;flex-wrap:wrap;gap:4px 16px;margin:8px 0;">';
            echo '<legend class="screen-reader-text">' . esc_html__('Members', 'openyacht') . '</legend>';

            foreach ($partners as $partner) {
                printf(
                    '<label style="min-width:220px;"><input type="checkbox" name="members[]" value="%d" %s> %s</label>',
                    $partner->id,
                    checked(in_array($partner->id, $members, true), true, false),
                    esc_html($partner->domain),
                );
            }

            echo '</fieldset>';
            echo '<div style="display:flex;gap:16px;align-items:center;">';
            submit_button(__('Save group', 'openyacht'), 'secondary', '', false);
            echo '<button type="submit" class="button-link button-link-delete" name="delete_group" value="1" onclick="return confirm(' . esc_attr(wp_json_encode(__('Delete this group? Listings sharing only through it will be tombstoned for its members.', 'openyacht'))) . ');">' . esc_html__('Delete group', 'openyacht') . '</button>';
            echo '</div>';
            echo '</form>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px;display:flex;gap:8px;align-items:center;">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="op" value="group_create">';
        wp_nonce_field(self::ACTION);
        echo '<input type="text" name="group_name" class="regular-text" placeholder="' . esc_attr__('New group name — e.g. Company sites', 'openyacht') . '" aria-label="' . esc_attr__('New group name', 'openyacht') . '">';
        submit_button(__('Add group', 'openyacht'), 'secondary', '', false);
        echo '</form>';
    }

    /**
     * Per-partner field-group grants (LS-14): the admin surface stays
     * two-dimensional — groups per partner, applied to every listing that
     * partner can see.
     */
    private function renderGrantsForm(string $domain): void
    {
        $partner = Services::partners()->findByDomain($domain);

        if ($partner === null) {
            echo '<h1>' . esc_html__('Partner not found', 'openyacht') . '</h1>';

            return;
        }

        $granted = $partner->grantedFieldGroups();
        $labels = [
            'pricing' => __('Pricing — asking price and currency', 'openyacht'),
            'location_exact' => __('Exact location — marina and coordinates (display region is always shared)', 'openyacht'),
            'media_original' => __('Original media — full-resolution imagery', 'openyacht'),
            'documents' => __('Documents', 'openyacht'),
            'vessel_identifiers' => __('Vessel identifiers — HIN, IMO, MMSI, official number', 'openyacht'),
            'history' => __('Price history', 'openyacht'),
        ];

        echo '<h1>' . esc_html(sprintf(
            /* translators: %s: partner domain. */
            __('Sharing with %s', 'openyacht'),
            $partner->domain,
        )) . '</h1>';
        echo '<p class="description">' . esc_html__('Withheld groups are nulled server-side in every payload this partner receives — it cannot distinguish "withheld" from "not on file".', 'openyacht') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="op" value="grants">';
        echo '<input type="hidden" name="domain" value="' . esc_attr($partner->domain) . '">';
        wp_nonce_field(self::ACTION);
        echo '<table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__('Granted field groups', 'openyacht') . '</th><td><fieldset>';

        foreach (\OpenYacht\Federation\FieldGroup::cases() as $group) {
            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="groups[]" value="%s" %s> %s</label>',
                esc_attr($group->value),
                checked(in_array($group, $granted, true), true, false),
                esc_html($labels[$group->value] ?? $group->value),
            );
        }

        echo '</fieldset></td></tr></table>';
        submit_button(__('Save sharing rules', 'openyacht'));
        echo '</form>';

        $this->renderScopeForm($partner);
        $this->renderSharedListings($partner);
    }

    /**
     * The partner's sharing scope: standard (today's behaviour) or
     * curated — a partner that receives ONLY listings explicitly selected
     * for it, directly or via a group. Explicit selections are additive,
     * so curating this partner never changes what any other partner sees.
     */
    private function renderScopeForm(\OpenYacht\Federation\Partner $partner): void
    {
        $labels = [
            'standard' => __('Standard — receives every listing shared with everyone, plus anything selected for it', 'openyacht'),
            'curated' => __('Curated — receives only listings explicitly selected for it (a yacht-show organiser, a trial, a press feed); saving this shows the listing picker', 'openyacht'),
        ];

        echo '<hr style="margin:2em 0;">';
        echo '<h2>' . esc_html__('Sharing scope', 'openyacht') . '</h2>';
        echo '<p class="description" style="max-width:640px;">' . esc_html__('Switching to curated tombstones every listing this partner saw only because it was shared with everyone; switching back resurfaces them. Listings selected for it are unaffected either way.', 'openyacht') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        echo '<input type="hidden" name="op" value="scope">';
        echo '<input type="hidden" name="domain" value="' . esc_attr($partner->domain) . '">';
        wp_nonce_field(self::ACTION);
        echo '<fieldset>';
        echo '<legend class="screen-reader-text">' . esc_html__('Sharing scope', 'openyacht') . '</legend>';

        foreach (\OpenYacht\Federation\SharingScope::cases() as $scope) {
            printf(
                '<label style="display:block;margin-bottom:4px;"><input type="radio" name="sharing_scope" value="%s" %s> %s</label>',
                esc_attr($scope->value),
                checked($partner->sharingScope->value, $scope->value, false),
                esc_html($labels[$scope->value] ?? $scope->value),
            );
        }

        echo '</fieldset>';
        submit_button(__('Save sharing scope', 'openyacht'), 'secondary');
        echo '</form>';
    }

    /**
     * The partner-side transpose of the listing editor's audience picker:
     * this partner's direct shares, one picker per listing type — sale
     * and charter are never mixed in one list, because the twins carry
     * different price information and sharing the wrong one leaks the
     * wrong price. Each form submits its type's FULL direct-share list
     * and the save is scoped to that type, so saving one list cannot
     * unshare the other. Group-derived shares render read-only with
     * their provenance; unticking a direct share never touches group
     * membership.
     *
     * Curated partners only: for a standard partner the list would
     * mislead — everything shared with everyone is visible regardless of
     * the checkboxes (selections only matter on selected-audience
     * listings, managed from each listing's own Sharing section). Any
     * existing selections are noted, never silently hidden: they stay in
     * place and apply the moment the scope flips.
     */
    private function renderSharedListings(\OpenYacht\Federation\Partner $partner): void
    {
        $directIds = Services::audience()->listingIdsForPartner($partner->id);

        if ($partner->sharingScope !== \OpenYacht\Federation\SharingScope::Curated) {
            if ($directIds !== []) {
                echo '<p class="description" style="max-width:640px;">' . esc_html(sprintf(
                    /* translators: %d: number of listings individually selected for this partner. */
                    _n(
                        'This partner has %d listing individually selected for it. The selection stays in place and is what it will receive if its scope is switched to curated.',
                        'This partner has %d listings individually selected for it. The selection stays in place and is what it will receive if its scope is switched to curated.',
                        count($directIds),
                        'openyacht',
                    ),
                    count($directIds),
                )) . '</p>';
            }

            return;
        }

        $groups = Services::partnerGroups();
        $partnerGroupIds = $groups->groupIdsForPartner($partner->id);
        $groupNames = [];

        foreach ($groups->all() as $group) {
            $groupNames[$group->id] = $group->name;
        }

        $byType = ['sale' => [], 'charter' => []];
        $sharedNamesByType = ['sale' => [], 'charter' => []];
        $namesByType = ['sale' => [], 'charter' => []];

        foreach (Services::listings()->all() as $listing) {
            if (! isset($byType[$listing->type])) {
                continue;
            }

            $viaGroups = array_intersect($groups->groupIdsForListing($listing->id), $partnerGroupIds);
            $shared = in_array($listing->id, $directIds, true) || $viaGroups !== [];
            $byType[$listing->type][] = ['listing' => $listing, 'viaGroups' => array_values($viaGroups), 'shared' => $shared];

            if ($listing->name !== null) {
                $namesByType[$listing->type][$listing->name] = true;

                if ($shared) {
                    $sharedNamesByType[$listing->type][$listing->name] = true;
                }
            }
        }

        echo '<hr style="margin:2em 0;">';
        echo '<h2>' . esc_html__('Shared listings', 'openyacht') . '</h2>';
        echo '<p class="description" style="max-width:640px;">' . esc_html__('Listings explicitly selected for this partner. Selections are additive — they share the listing with this partner under any audience except "no one" — and they are what a curated partner\'s feed is built from. A share the partner gets via a group is shown with its provenance and is managed on the group, not here.', 'openyacht') . '</p>';

        $headings = ['sale' => __('Sale listings', 'openyacht'), 'charter' => __('Charter listings', 'openyacht')];

        foreach ($headings as $type => $heading) {
            $sisterType = $type === 'sale' ? 'charter' : 'sale';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:12px 0;padding:12px;border:1px solid #c3c4c7;background:#fff;max-width:640px;">';
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
            echo '<input type="hidden" name="op" value="shares">';
            echo '<input type="hidden" name="domain" value="' . esc_attr($partner->domain) . '">';
            echo '<input type="hidden" name="share_type" value="' . esc_attr($type) . '">';
            wp_nonce_field(self::ACTION);
            echo '<h3 style="margin-top:0;">' . esc_html($heading) . '</h3>';

            if ($byType[$type] === []) {
                echo '<p>' . esc_html__('No listings of this type yet.', 'openyacht') . '</p>';
                echo '</form>';
                continue;
            }

            echo '<p><label><input type="checkbox" data-oy-toggle-shares> ' . esc_html__('Select all', 'openyacht') . '</label></p>';
            echo '<fieldset style="max-height:320px;overflow-y:auto;border-top:1px solid #dcdcde;padding-top:8px;">';
            echo '<legend class="screen-reader-text">' . esc_html($heading) . '</legend>';

            foreach ($byType[$type] as $row) {
                $listing = $row['listing'];
                $notes = [];

                if ($listing->status !== \OpenYacht\Federation\ListingStatus::Active) {
                    $notes[] = $listing->status->value;
                }

                if ($listing->audience === \OpenYacht\Federation\Audience::None) {
                    $notes[] = __('audience: no one — hidden from all partners until re-shared', 'openyacht');
                }

                if ($row['viaGroups'] !== []) {
                    $names = array_map(static fn (int $id): string => $groupNames[$id] ?? "#{$id}", $row['viaGroups']);
                    $notes[] = sprintf(
                        /* translators: %s: comma-separated partner group names. */
                        __('shared via %s', 'openyacht'),
                        implode(', ', $names),
                    );
                }

                // The sister-listing hint, keyed on the shared vessel: the
                // sale/charter twins carry different price information, so
                // sharing the wrong one leaks the wrong price. A hint, not
                // an auto-share.
                if ($row['shared']
                    && $listing->name !== null
                    && isset($namesByType[$sisterType][$listing->name])
                    && ! isset($sharedNamesByType[$sisterType][$listing->name])) {
                    $notes[] = sprintf(
                        /* translators: %s: listing type (sale or charter). */
                        __('its %s twin exists and is not shared — the twins carry different price information', 'openyacht'),
                        $sisterType,
                    );
                }

                printf(
                    '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="listing_ids[]" value="%d" %s> %s%s</label>',
                    $listing->id,
                    checked(in_array($listing->id, $directIds, true), true, false),
                    esc_html($listing->name ?? "#{$listing->id}"),
                    $notes === [] ? '' : ' <span class="description">(' . esc_html(implode('; ', $notes)) . ')</span>',
                );
            }

            echo '</fieldset>';
            submit_button(
                $type === 'sale' ? __('Save shared sale listings', 'openyacht') : __('Save shared charter listings', 'openyacht'),
                'secondary',
                '',
                true,
            );
            echo '</form>';
        }

        // Select-all toggles only its own picker's checkboxes.
        echo '<script>document.querySelectorAll("[data-oy-toggle-shares]").forEach(function (toggle) {'
            . 'toggle.addEventListener("change", function () {'
            . 'toggle.closest("form").querySelectorAll("input[name=\'listing_ids[]\']").forEach(function (box) { box.checked = toggle.checked; });'
            . '});'
            . '});</script>';
    }

    private function renderAddForm(): void
    {
        ?>
        <h2><?php esc_html_e('Add partner', 'openyacht'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="op" value="add">
            <?php wp_nonce_field(self::ACTION); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="openyacht_domain"><?php esc_html_e('Identity domain', 'openyacht'); ?></label></th>
                    <td>
                        <input name="domain" type="text" id="openyacht_domain" class="regular-text" placeholder="broker.example" required>
                        <p class="description"><?php esc_html_e('The partner node\'s identity domain. Its discovery document is fetched over verified TLS and the partner starts as provisional.', 'openyacht'); ?></p>
                    </td>
                </tr>
            </table>
            <details style="margin:4px 0 8px;">
                <summary style="cursor:pointer;"><?php esc_html_e('Advanced: register a key out of band', 'openyacht'); ?></summary>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="openyacht_public_key"><?php esc_html_e('Public key', 'openyacht'); ?></label></th>
                        <td>
                            <input name="public_key" type="text" id="openyacht_public_key" class="large-text" placeholder="base64 Ed25519 public key">
                            <p class="description"><?php esc_html_e('Only for partner nodes this site cannot resolve or reach (intranet nodes, split hosting, local development). The pasted key is stored pinned instead of fetching the partner\'s discovery document. Leave empty for a normal add.', 'openyacht'); ?></p>
                        </td>
                    </tr>
                </table>
            </details>
            <?php submit_button(__('Add partner', 'openyacht')); ?>
        </form>
        <?php
    }
}

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
            'dashicons-admin-site-alt3',
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
        $args = $notice === 'approved'
            ? ['page' => self::MENU_SLUG, 'action' => 'grants', 'domain' => $domain, 'openyacht_notice' => $notice]
            : ['page' => self::MENU_SLUG, 'openyacht_notice' => $notice];

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

        $messages = [
            'added' => __('Partner added (provisional). Approve it to start receiving requests as a verified partner.', 'openyacht'),
            'approved' => __('Partner approved — it starts receiving listings on its next poll. Review below what it gets: every field group is granted by default.', 'openyacht'),
            'blocked' => __('Partner blocked. All its requests will be rejected.', 'openyacht'),
            'refreshed' => __('Partner keys refreshed.', 'openyacht'),
            'grants' => __('Sharing rules saved. They apply to every payload this partner receives from now on.', 'openyacht'),
            'group_saved' => __('Group saved. Joined partners pick up its listings on their next poll; removed partners receive tombstones.', 'openyacht'),
            'directory_refreshed' => __('Node directory refreshed from openyacht.org.', 'openyacht'),
            'group_deleted' => __('Group deleted. Listings that shared only through it were tombstoned for its members.', 'openyacht'),
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

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
                    $notice = 'grants';
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
                        'refresh' => $service->refreshKeys($partner),
                    };
                    $notice = $op === 'refresh' ? 'refreshed' : ($op === 'approve' ? 'approved' : 'blocked');
                    break;
            }
        } catch (Throwable $exception) {
            set_transient('openyacht_partner_error_' . get_current_user_id(), $exception->getMessage(), 60);
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'openyacht_notice' => $notice],
            admin_url('admin.php'),
        ));
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
            'approved' => __('Partner approved.', 'openyacht'),
            'blocked' => __('Partner blocked. All its requests will be rejected.', 'openyacht'),
            'refreshed' => __('Partner keys refreshed.', 'openyacht'),
            'grants' => __('Sharing rules saved. They apply to every payload this partner receives from now on.', 'openyacht'),
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
        echo '</div>';
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
                <tr>
                    <th scope="row"><label for="openyacht_public_key"><?php esc_html_e('Public key (optional)', 'openyacht'); ?></label></th>
                    <td>
                        <input name="public_key" type="text" id="openyacht_public_key" class="large-text" placeholder="base64 Ed25519 public key">
                        <p class="description"><?php esc_html_e('Out-of-band registration: store this pinned key instead of fetching the well-known document — for partner nodes this site cannot resolve.', 'openyacht'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('Add partner', 'openyacht')); ?>
        </form>
        <?php
    }
}

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

        $table = new PartnersTable();
        $table->prepare_items();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('OpenYacht Partners', 'openyacht') . '</h1>';
        $table->display();
        $this->renderAddForm();
        echo '</div>';
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

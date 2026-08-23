<?php

declare(strict_types=1);

namespace OpenYacht;

use OpenYacht\Admin\Settings;

final class Activation
{
    public static function activate(): void
    {
        $errors = (new Requirements())->errors();

        if ($errors !== []) {
            $message = '<p><strong>' . esc_html__('OpenYacht cannot activate:', 'openyacht') . '</strong></p><p>'
                . implode('</p><p>', array_map('esc_html', $errors))
                . '</p>';

            wp_die(
                $message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
                esc_html__('OpenYacht activation failed', 'openyacht'),
                ['back_link' => true],
            );
        }

        global $wpdb;

        (new Schema($wpdb))->install();
        NodeIdentity::ensure();

        // Node keypair, generated once at installation (FP-5 pairs the UUID
        // above with FP-3's Ed25519 key). Existing keys are left untouched.
        $keys = new Federation\KeyManager(
            new Federation\WpdbKeyRepository($wpdb, Federation\KeyEncryption::fromWpSalts()),
        );
        $keys->ensureActiveKey();

        // No plugin option is ever autoloaded. The settings option is
        // pre-created here so its first save through options.php inherits
        // autoload=off instead of the add_option default.
        add_option(Settings::OPTION, [], '', false);

        foreach ([Settings::OPTION, Schema::OPTION, NodeIdentity::OPTION] as $option) {
            wp_set_option_autoload($option, false);
        }
    }
}

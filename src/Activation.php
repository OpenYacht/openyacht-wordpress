<?php

declare(strict_types=1);

namespace OpenYacht;

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
    }
}

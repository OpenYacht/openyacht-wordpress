<?php

declare(strict_types=1);

namespace OpenYacht;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\Partner;

/**
 * Admin email notifications for federation events a human should act on:
 * an unknown node introducing itself (it sits provisional until approved,
 * so nobody watching the Partners screen means nobody federating), and a
 * partner's node UUID changing (a possible domain takeover, FP-11).
 *
 * Recipient defaults to the site admin email; filter
 * openyacht_notification_email to reroute, or return '' to silence.
 */
final class Notifications
{
    public function register(): void
    {
        add_action('openyacht_partner_first_contact', [$this, 'partnerFirstContact']);
        add_action('openyacht_partner_uuid_changed', [$this, 'partnerUuidChanged']);
    }

    public function partnerFirstContact(Partner $partner): void
    {
        $this->send(
            sprintf(
                /* translators: 1: site name, 2: partner domain. */
                __('[%1$s] New OpenYacht node awaiting approval: %2$s', 'openyacht'),
                get_bloginfo('name'),
                $partner->domain,
            ),
            sprintf(
                /* translators: 1: partner domain, 2: partners screen URL. */
                __(
                    "The node %1\$s contacted this site for the first time and was recorded as a provisional partner (trust on first use).\n\nProvisional partners can deliver signed content but stay untrusted until a human approves them. Review and approve or block it here:\n%2\$s",
                    'openyacht',
                ),
                $partner->domain,
                $this->partnersUrl(),
            ),
        );
    }

    public function partnerUuidChanged(Partner $partner): void
    {
        $this->send(
            sprintf(
                /* translators: 1: site name, 2: partner domain. */
                __('[%1$s] OpenYacht partner identity changed: %2$s', 'openyacht'),
                get_bloginfo('name'),
                $partner->domain,
            ),
            sprintf(
                /* translators: 1: partner domain, 2: partners screen URL. */
                __(
                    "The node UUID served by %1\$s changed, meaning the domain now hosts a different installation. The partner was downgraded to provisional and needs re-approval before it is trusted again.\n\nIf you were not expecting this (a migration or reinstall on their side), treat it as a possible domain takeover and contact the partner out of band before re-approving:\n%2\$s",
                    'openyacht',
                ),
                $partner->domain,
                $this->partnersUrl(),
            ),
        );
    }

    private function partnersUrl(): string
    {
        return add_query_arg(['page' => Admin\PartnersPage::MENU_SLUG], admin_url('admin.php'));
    }

    private function send(string $subject, string $message): void
    {
        $to = apply_filters('openyacht_notification_email', get_option('admin_email'));

        if (! is_string($to) || $to === '') {
            return;
        }

        wp_mail($to, $subject, $message);
    }
}

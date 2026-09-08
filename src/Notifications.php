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
 * so nobody watching the Partners screen means nobody federating), a
 * known partner sending a partnership request, and a partner's node UUID
 * changing (a possible domain takeover, FP-11).
 *
 * Recipient defaults to the site admin email; filter
 * openyacht_notification_email to reroute, or return '' to silence.
 */
final class Notifications
{
    public function register(): void
    {
        add_action('openyacht_partner_first_contact', [$this, 'partnerFirstContact']);
        add_action('openyacht_partnership_requested', [$this, 'partnershipRequested']);
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
                /* translators: 1: partner domain, 2: the partner's request message and contact (may be empty), 3: partners screen URL. */
                __(
                    "The node %1\$s contacted this site for the first time and was recorded as a provisional partner (trust on first use).\n\n%2\$sProvisional partners can deliver signed content but stay untrusted until a human approves them. Review and approve or block it here:\n%3\$s",
                    'openyacht',
                ),
                $partner->domain,
                $this->requestLines($partner),
                $this->partnersUrl(),
            ),
        );
    }

    /**
     * A partner this site already knew sent a partnership request: its
     * first contact was a sync poll, or it is asking again. The message
     * and contact are what the approving person decides on (FP-13).
     */
    public function partnershipRequested(Partner $partner): void
    {
        $this->send(
            sprintf(
                /* translators: 1: site name, 2: partner domain. */
                __('[%1$s] OpenYacht partnership request from %2$s', 'openyacht'),
                get_bloginfo('name'),
                $partner->domain,
            ),
            sprintf(
                /* translators: 1: partner domain, 2: the partner's trust level, 3: the partner's request message and contact (may be empty), 4: partners screen URL. */
                __(
                    "The node %1\$s, already registered on this site as a %2\$s partner, sent a partnership request.\n\n%3\$sReview it and approve or block the partner here:\n%4\$s",
                    'openyacht',
                ),
                $partner->domain,
                $partner->trustLevel->value,
                $this->requestLines($partner),
                $this->partnersUrl(),
            ),
        );
    }

    /**
     * The sender's own words and a way to reply, when it gave them — the
     * "who are you and why" the trust decision is made on. Empty when the
     * partner never sent a request (its first contact was a poll).
     */
    private function requestLines(Partner $partner): string
    {
        $lines = '';

        if ($partner->requestMessage !== null) {
            /* translators: %s: the partner's request message. */
            $lines .= sprintf(__("Their message:\n%s\n\n", 'openyacht'), $partner->requestMessage);
        }

        if ($partner->requestContactEmail !== null) {
            /* translators: %s: the partner's contact email. */
            $lines .= sprintf(__("Contact: %s\n\n", 'openyacht'), $partner->requestContactEmail);
        }

        return $lines;
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

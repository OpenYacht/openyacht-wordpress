<?php

declare(strict_types=1);

namespace OpenYacht\Admin;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\Partner;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Services;

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Standard WP list table of federation partners with the trust actions as
 * row actions.
 */
final class PartnersTable extends \WP_List_Table
{
    public function __construct()
    {
        parent::__construct([
            'singular' => 'partner',
            'plural' => 'partners',
            'ajax' => false,
        ]);
    }

    /**
     * @return array<string, string>
     */
    public function get_columns(): array
    {
        return [
            'domain' => __('Domain', 'openyacht'),
            'trust' => __('Trust', 'openyacht'),
            'sharing' => __('Sharing', 'openyacht'),
            'copies' => __('Synced listings', 'openyacht'),
            'last_ok' => __('Last contact', 'openyacht'),
            'health' => __('Health', 'openyacht'),
        ];
    }

    public function prepare_items(): void
    {
        $this->items = Services::partners()->all();
        $this->_column_headers = [$this->get_columns(), [], []];
    }

    /**
     * @param Partner $item
     */
    public function column_domain($item): string
    {
        $actions = [];

        if ($item->trustLevel !== TrustLevel::Verified) {
            // The array key becomes the row-action span's CSS class, and
            // core's common.css globally hides `.approve` (comments-list
            // styling) — so the key must not be literally "approve".
            $actions['approve_partner'] = $this->actionButton($item, 'approve', __('Approve', 'openyacht'));
        }

        if ($item->trustLevel !== TrustLevel::Blocked) {
            $actions['sync_now'] = $this->actionButton($item, 'sync_now', __('Sync now', 'openyacht'));
            $actions['block'] = $this->actionButton($item, 'block', __('Block', 'openyacht'));
        }

        $actions['refresh'] = $this->actionButton($item, 'refresh', __('Refresh keys', 'openyacht'));

        // Optional push layer (API-11): receive the partner's changes as
        // they happen instead of on the hourly poll. Verified only — the
        // inbox refuses anyone else, like the feed.
        if ($item->trustLevel === TrustLevel::Verified) {
            $actions['pushes'] = $item->isPushSubscribed()
                ? $this->actionButton($item, 'unsubscribe', __('Unsubscribe from pushes', 'openyacht'))
                : $this->actionButton($item, 'subscribe', __('Subscribe to pushes', 'openyacht'));
        }

        // Lifecycle step 1, on demand: a partner added before the sender
        // existed, or one whose administrators need asking again.
        if ($item->trustLevel !== TrustLevel::Blocked) {
            $actions['introduce'] = $this->actionButton(
                $item,
                'introduce',
                $item->requestSentAt === null
                    ? __('Send partnership request', 'openyacht')
                    : __('Resend partnership request', 'openyacht'),
            );
        }

        // Undo for a mistaken add: only while nothing has been received —
        // once copies exist the row is their provenance anchor and Block
        // is how a partnership ends.
        if (Services::partnerService()->isRemovable($item)) {
            $actions['remove'] = $this->actionButton(
                $item,
                'remove',
                __('Remove', 'openyacht'),
                /* translators: %s: partner domain */
                sprintf(__('Remove %s? Nothing has been received from it. Its sharing selections and group memberships go with it.', 'openyacht'), $item->domain),
            );
        }

        $grantsUrl = add_query_arg(
            ['page' => PartnersPage::MENU_SLUG, 'action' => 'grants', 'domain' => $item->domain],
            admin_url('admin.php'),
        );
        $actions['grants'] = '<a href="' . esc_url($grantsUrl) . '">' . esc_html__('Sharing', 'openyacht') . '</a>';

        $notes = '';

        if ($item->pinnedKeyId !== null) {
            /* translators: %s: the partner's pinned signing key ID */
            $notes .= '<br><span class="description">' . esc_html(sprintf(__('Pinned key: %s', 'openyacht'), $item->pinnedKeyId)) . '</span>';
        }

        if ($item->requestSentAt !== null) {
            /* translators: %s: UTC datetime the partnership request reached the partner. */
            $notes .= '<br><span class="description">' . esc_html(sprintf(__('Partnership request sent %s UTC', 'openyacht'), $item->requestSentAt)) . '</span>';
        }

        if ($item->pushSubscribedAt !== null) {
            /* translators: %s: UTC datetime this node subscribed to the partner's pushes. */
            $notes .= '<br><span class="description">' . esc_html(sprintf(__('Receiving its pushes since %s UTC; polled daily to reconcile', 'openyacht'), $item->pushSubscribedAt)) . '</span>';
        }

        if ($item->pushCallbackUrl !== null) {
            $delivery = $item->pushLastDeliveredAt !== null
                /* translators: %s: UTC datetime of the last successful push delivery to the partner. */
                ? sprintf(__('last delivered %s UTC', 'openyacht'), $item->pushLastDeliveredAt)
                : __('nothing delivered yet', 'openyacht');

            if ($item->pushLastFailedAt !== null && $item->pushLastFailedAt > (string) $item->pushLastDeliveredAt) {
                /* translators: %s: UTC datetime of the last failed push delivery to the partner. */
                $delivery .= ', ' . sprintf(__('last failed %s UTC', 'openyacht'), $item->pushLastFailedAt);
            }

            /* translators: 1: the partner's callback URL, 2: delivery status text. */
            $notes .= '<br><span class="description">' . esc_html(sprintf(__('Pushing changes to %1$s (%2$s)', 'openyacht'), $item->pushCallbackUrl, $delivery)) . '</span>';
        }

        return '<strong>' . esc_html($item->domain) . '</strong>' . $notes . $this->receivedRequest($item) . $this->row_actions($actions);
    }

    /**
     * The partner's own introduction — what the approve/block decision is
     * made on (FP-13) — shown right where that decision is taken.
     */
    private function receivedRequest(Partner $partner): string
    {
        if ($partner->requestedAt === null) {
            return '';
        }

        $html = '<div style="margin:6px 0 2px;padding:6px 10px;border-left:4px solid #72aee6;background:#f0f6fc;max-width:520px;">'
            /* translators: %s: UTC datetime the partner's request arrived. */
            . '<strong>' . esc_html(sprintf(__('Requested partnership %s UTC', 'openyacht'), $partner->requestedAt)) . '</strong>';

        if ($partner->requestMessage !== null) {
            $html .= '<br>' . esc_html($partner->requestMessage);
        }

        if ($partner->requestContactEmail !== null) {
            $contact = is_email($partner->requestContactEmail)
                ? '<a href="' . esc_url('mailto:' . $partner->requestContactEmail) . '">' . esc_html($partner->requestContactEmail) . '</a>'
                : esc_html($partner->requestContactEmail);
            /* translators: %s: the partner's contact email (HTML). */
            $html .= '<br><span class="description">' . sprintf(esc_html__('Contact: %s', 'openyacht'), $contact) . '</span>';
        }

        return $html . '</div>';
    }

    /**
     * @param Partner $item
     */
    public function column_trust($item): string
    {
        return esc_html($item->trustLevel->value);
    }

    /**
     * @param Partner $item
     */
    public function column_sharing($item): string
    {
        $granted = count($item->grantedFieldGroups());
        $total = count(\OpenYacht\Federation\FieldGroup::cases());
        $label = $item->fieldGroups === null
            ? __('All fields', 'openyacht')
            : sprintf(
                /* translators: 1: granted field-group count, 2: total field-group count. */
                __('%1$d of %2$d field groups', 'openyacht'),
                $granted,
                $total,
            );

        if ($item->sharingScope === \OpenYacht\Federation\SharingScope::Curated) {
            $label = __('Curated', 'openyacht') . ' · ' . $label;
        }

        $url = add_query_arg(
            ['page' => PartnersPage::MENU_SLUG, 'action' => 'grants', 'domain' => $item->domain],
            admin_url('admin.php'),
        );

        return '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    /**
     * @param Partner $item
     */
    public function column_copies($item): string
    {
        return esc_html((string) Services::copies()->countForPartner($item->id));
    }

    /**
     * @param Partner $item
     */
    public function column_last_ok($item): string
    {
        return esc_html($item->lastOkAt ?? __('never', 'openyacht'));
    }

    /**
     * @param Partner $item
     */
    public function column_health($item): string
    {
        if ($item->isStale()) {
            // FP-15: copies from an unreachable partner are flagged stale.
            return '<span style="color:#b32d2e;font-weight:600;">' . esc_html__('Stale', 'openyacht') . '</span>';
        }

        if ($item->consecutiveFailures > 0) {
            return esc_html(sprintf(
                /* translators: %d: number of consecutive failed sync attempts. */
                __('%d failed attempt(s), backing off', 'openyacht'),
                $item->consecutiveFailures,
            ));
        }

        return esc_html__('OK', 'openyacht');
    }

    /**
     * @param mixed $item
     */
    public function column_default($item, $column_name): string
    {
        return '';
    }

    private function actionButton(Partner $partner, string $op, string $label, ?string $confirm = null): string
    {
        $form = '<form method="post" action="%s" style="display:inline"%s>'
            . '<input type="hidden" name="action" value="openyacht_partner">'
            . '<input type="hidden" name="op" value="%s">'
            . '<input type="hidden" name="domain" value="%s">'
            . '%s<button type="submit" class="button-link">%s</button></form>';

        return sprintf(
            $form,
            esc_url(admin_url('admin-post.php')),
            $confirm === null ? '' : ' onsubmit="return confirm(\'' . esc_js($confirm) . '\');"',
            esc_attr($op),
            esc_attr($partner->domain),
            wp_nonce_field('openyacht_partner', '_wpnonce', true, false),
            esc_html($label),
        );
    }
}

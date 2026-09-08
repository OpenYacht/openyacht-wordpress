<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * How a partner answered this node's signed partnership request
 * (PartnerService::introduce). The partner row is saved before the
 * request is attempted, so every outcome — failure included — is
 * reported to the operator, never thrown.
 *
 * // federation-protocol.md §Partner Lifecycle
 */
enum IntroductionOutcome: string
{
    /** Registered over there as provisional; a human on their side decides next. */
    case Delivered = 'delivered';

    /** They already list this node as a verified partner. */
    case Accepted = 'accepted';

    /** They have blocked this node. */
    case Blocked = 'blocked';

    /** Not delivered: unreachable, refused by the outbound guard, no signing key, or an unexpected answer. */
    case Failed = 'failed';

    /**
     * Whether the request reached the partner. Only these outcomes stamp
     * request_sent_at.
     */
    public function reachedPartner(): bool
    {
        return $this === self::Delivered || $this === self::Accepted;
    }
}

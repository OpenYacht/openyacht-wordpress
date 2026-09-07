<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * The partner answered a sync poll with PARTNER_PROVISIONAL: authenticated,
 * delivered, and correctly refused because a human on their side has not
 * approved this node yet. A healthy state, not a failure — it never counts
 * toward the backoff, so polling continues on the normal schedule and picks
 * up the moment approval lands.
 *
 * // api-design.md §Errors (PARTNER_PROVISIONAL)
 * // federation-protocol.md §Health and Failure Handling
 */
final class PartnerAwaitingApproval extends RuntimeException
{
}

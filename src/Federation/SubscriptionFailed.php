<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Subscribing to a partner's pushes did not happen: the partner is not
 * verified here, does not offer the feature, could not be reached, or
 * refused. The message is for the operator.
 */
final class SubscriptionFailed extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Network-level failure of an outbound federation request (DNS, TLS,
 * timeout) — the request never produced an HTTP response.
 */
final class TransportException extends RuntimeException
{
}

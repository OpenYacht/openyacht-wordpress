<?php

declare(strict_types=1);

namespace OpenYacht\Http;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\NodeConfig;

/**
 * Per-partner federation rate limiting, enforcing the limits advertised in
 * capabilities with 429 + Retry-After (API-9). Fixed hourly window per
 * partner — deliberately minimal; the RECOMMENDED default is 500/h.
 */
final class RateLimiter
{
    /**
     * Record a hit. Returns null when allowed, or the Retry-After seconds
     * when the partner is over its hourly budget.
     */
    public function hit(int $partnerId): ?int
    {
        $window = gmdate('YmdH');
        $key = "openyacht_rate_{$partnerId}_{$window}";
        $count = (int) get_transient($key);

        if ($count >= NodeConfig::RATE_PER_HOUR) {
            $windowEnds = (int) strtotime(gmdate('Y-m-d H:00:00') . ' UTC') + 3600;

            return max(1, $windowEnds - time());
        }

        set_transient($key, $count + 1, 2 * 3600);

        return null;
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Http;

use OpenYacht\Federation\ErrorCode;
use OpenYacht\Federation\NodeConfig;
use OpenYacht\Federation\WellKnownDocument;

/**
 * Serves the federation HTTP surface at the site root — the spec's exact
 * paths (/.well-known/openyacht, /openyacht/v1/*), not /wp-json/....
 *
 * Deliberately not the WP REST API: signature verification MUST see the
 * raw request-target (path + query) and raw body bytes, which
 * WP_REST_Request re-parses. Requests are matched on parse_request straight
 * from $_SERVER['REQUEST_URI'], needing no rewrite rules or flushes.
 *
 * // federation-protocol.md §Request Signing (raw signing-string inputs)
 */
final class Router
{
    public function __construct(private readonly WellKnownDocument $wellKnown)
    {
    }

    public function register(): void
    {
        add_action('parse_request', [$this, 'maybeDispatch'], 0);
    }

    public function maybeDispatch(): void
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

        if ($path === '/.well-known/openyacht') {
            $this->guardIdentityDomain();
            JsonResponder::send($this->wellKnown->toArray());
        }

        if (str_starts_with($path, '/openyacht/v1/')) {
            $this->guardIdentityDomain();
            $this->dispatchV1(substr($path, strlen('/openyacht/v1/')));
        }
    }

    private function dispatchV1(string $route): never
    {
        switch ($route) {
            case 'health':
                JsonResponder::send([
                    'status' => 'ok',
                    'time' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                // JsonResponder::send() exits.
                // no break
            case 'capabilities':
                // Unsigned capability negotiation (API-6). This node
                // advertises no optional features yet: `features` lists
                // optional protocol features only — the sale-listing schema
                // and updated_since sync are the mandatory baseline.
                JsonResponder::send([
                    'protocol_versions' => NodeConfig::PROTOCOL_VERSIONS,
                    'features' => [
                        'subscriptions' => false,
                        'charter_listings' => false,
                        'media_hashes' => true,
                    ],
                    'limits' => [
                        'page_size_max' => NodeConfig::PAGE_SIZE_MAX,
                        'rate_per_hour' => NodeConfig::RATE_PER_HOUR,
                    ],
                ]);
                // JsonResponder::send() exits.
                // no break
            default:
                JsonResponder::error(ErrorCode::NotFound, 'Unknown federation endpoint.');
        }
    }

    /**
     * Refuses federation routes on any host other than the identity domain,
     * so the node's identity cannot fork.
     *
     * // federation-protocol.md §Choosing the identity domain
     */
    private function guardIdentityDomain(): void
    {
        $identityDomain = NodeConfig::identityDomain();
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $requestHost = (string) preg_replace('/:\d+$/', '', $requestHost);

        if ($identityDomain === '' || $requestHost !== $identityDomain) {
            nocache_headers();
            status_header(404);
            exit;
        }
    }
}

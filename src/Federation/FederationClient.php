<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Outbound HTTP boundary to a partner's federation API; SignedClient is
 * the wp_remote_* implementation, tests substitute a canned double.
 */
interface FederationClient
{
    public function get(Partner $partner, string $pathWithQuery): HttpResponse;

    /**
     * @param array<string, mixed> $payload
     */
    public function post(Partner $partner, string $pathWithQuery, array $payload): HttpResponse;

    public function delete(Partner $partner, string $pathWithQuery): HttpResponse;

    /**
     * A signed POST to a partner-registered callback URL rather than a
     * path on its identity domain: the push delivery (API-10). The
     * signing string is built from the callback's own host and path, so
     * the receiver verifies it with the standard procedure.
     *
     * @param array<string, mixed> $payload
     */
    public function postCallback(string $url, array $payload): HttpResponse;
}

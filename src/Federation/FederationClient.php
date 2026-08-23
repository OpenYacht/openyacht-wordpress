<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

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
}

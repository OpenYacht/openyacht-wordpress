<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Append-only federation log: partner lifecycle events and (later) every
 * inbound request with its verification outcome — the usage-compliance
 * evidence base the API spec calls for.
 *
 * // api-design.md §Implementation notes (request logging)
 */
interface Logger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(
        string $channel,
        string $message,
        ?string $outcome = null,
        ?int $partnerId = null,
        ?string $endpoint = null,
        array $context = [],
    ): void;
}

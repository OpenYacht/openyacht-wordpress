<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\FederationClient;
use OpenYacht\Federation\HttpResponse;
use OpenYacht\Federation\Partner;

/**
 * Canned federation responses, consumed in FIFO order per request; records
 * every requested path for assertions.
 */
final class FakeFederationClient implements FederationClient
{
    /** @var list<HttpResponse> */
    public array $queue = [];

    /** @var list<string> */
    public array $requestedPaths = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function queueJson(array $payload, int $status = 200): void
    {
        $this->queue[] = new HttpResponse($status, (string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    public function get(Partner $partner, string $pathWithQuery): HttpResponse
    {
        $this->requestedPaths[] = $pathWithQuery;

        return array_shift($this->queue) ?? new HttpResponse(500, 'queue empty');
    }

    public function post(Partner $partner, string $pathWithQuery, array $payload): HttpResponse
    {
        $this->requestedPaths[] = $pathWithQuery;

        return array_shift($this->queue) ?? new HttpResponse(500, 'queue empty');
    }
}

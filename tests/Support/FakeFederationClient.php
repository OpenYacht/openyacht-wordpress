<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\FederationClient;
use OpenYacht\Federation\HttpResponse;
use OpenYacht\Federation\Partner;
use Throwable;

/**
 * Canned federation responses, consumed in FIFO order per request; records
 * every request for assertions. A queued Throwable is thrown in its turn
 * (an unreachable partner, a guard refusal).
 */
final class FakeFederationClient implements FederationClient
{
    /** @var list<HttpResponse|Throwable> */
    public array $queue = [];

    /** @var list<string> */
    public array $requestedPaths = [];

    /** @var list<array{method: string, path: string, payload: array<string, mixed>|null}> */
    public array $requests = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function queueJson(array $payload, int $status = 200): void
    {
        $this->queue[] = new HttpResponse($status, (string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    public function queueFailure(Throwable $failure): void
    {
        $this->queue[] = $failure;
    }

    public function get(Partner $partner, string $pathWithQuery): HttpResponse
    {
        return $this->answer('GET', $pathWithQuery, null);
    }

    public function post(Partner $partner, string $pathWithQuery, array $payload): HttpResponse
    {
        return $this->answer('POST', $pathWithQuery, $payload);
    }

    public function delete(Partner $partner, string $pathWithQuery): HttpResponse
    {
        return $this->answer('DELETE', $pathWithQuery, null);
    }

    public function postCallback(string $url, array $payload): HttpResponse
    {
        return $this->answer('POST', $url, $payload);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function answer(string $method, string $pathWithQuery, ?array $payload): HttpResponse
    {
        $this->requestedPaths[] = $pathWithQuery;
        $this->requests[] = ['method' => $method, 'path' => $pathWithQuery, 'payload' => $payload];

        $next = array_shift($this->queue) ?? new HttpResponse(500, 'queue empty');

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}

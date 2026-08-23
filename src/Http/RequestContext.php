<?php

declare(strict_types=1);

namespace OpenYacht\Http;

/**
 * The raw inbound request exactly as received: the signing string must be
 * rebuilt from the raw request-target and raw body bytes, never from
 * re-parsed or re-encoded copies.
 *
 * // federation-protocol.md §Request Signing
 */
final class RequestContext
{
    /**
     * @param array<string, string> $headers lowercase header name => value
     */
    public function __construct(
        public readonly string $method,
        public readonly string $pathWithQuery,
        public readonly string $host,
        public readonly string $rawBody,
        private readonly array $headers,
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_') && is_string($value)) {
                $headers[strtolower(str_replace('_', '-', substr((string) $key, 5)))] = $value;
            }
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return new self(
            method: strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            pathWithQuery: (string) ($_SERVER['REQUEST_URI'] ?? ''),
            host: (string) preg_replace('/:\d+$/', '', $host),
            rawBody: (string) file_get_contents('php://input'),
            headers: $headers,
        );
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}

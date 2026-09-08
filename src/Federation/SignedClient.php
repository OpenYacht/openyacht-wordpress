<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Outbound HTTP to a partner's federation API, signed with this node's
 * active key (FP-6). Always HTTPS with strict TLS verification (FP-2).
 *
 * The POST body is encoded once (JSON_UNESCAPED_SLASHES) and that exact
 * byte string is both signed and sent — the signature covers the raw body.
 *
 * // federation-protocol.md §Request Signing
 */
final class SignedClient implements FederationClient
{
    public function __construct(
        private readonly Signer $signer,
        private readonly OutboundUrlGuard $guard = new OutboundUrlGuard(),
    ) {
    }

    public function get(Partner $partner, string $pathWithQuery): HttpResponse
    {
        return $this->send($partner, 'GET', $pathWithQuery, null);
    }

    public function post(Partner $partner, string $pathWithQuery, array $payload): HttpResponse
    {
        return $this->send($partner, 'POST', $pathWithQuery, self::encode($payload));
    }

    public function delete(Partner $partner, string $pathWithQuery): HttpResponse
    {
        return $this->send($partner, 'DELETE', $pathWithQuery, null);
    }

    public function postCallback(string $url, array $payload): HttpResponse
    {
        // The callback was registered by the partner, so it is untrusted
        // input to a server-side POST: guard it at every send, not only at
        // registration — a delivery can run long after (FP-14).
        try {
            $this->guard->assertPublicHttpsUrl($url);
        } catch (BlockedOutboundHost $e) {
            throw new TransportException("Refusing delivery to {$url}: " . $e->getMessage(), 0, $e);
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $pathWithQuery = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return $this->request('POST', $host, $pathWithQuery, self::encode($payload));
    }

    private function send(Partner $partner, string $method, string $pathWithQuery, ?string $rawBody): HttpResponse
    {
        // The partner domain is stored from its (untrusted) discovery
        // document, so guard it against SSRF before connecting and never
        // follow a redirect that could bounce to a private host or plain
        // HTTP (FP-2, FP-14).
        try {
            $this->guard->assertPublicHost($partner->domain);
        } catch (BlockedOutboundHost $e) {
            throw new TransportException(
                "Refusing request to {$partner->domain}{$pathWithQuery}: " . $e->getMessage(),
                0,
                $e,
            );
        }

        return $this->request($method, $partner->domain, $pathWithQuery, $rawBody);
    }

    /**
     * The URL is rebuilt from the guarded host and path, so it can carry
     * neither port nor userinfo whatever was registered.
     */
    private function request(string $method, string $host, string $pathWithQuery, ?string $rawBody): HttpResponse
    {
        $headers = $this->signer->headers($method, $pathWithQuery, $host, $rawBody ?? '');

        if ($rawBody !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request("https://{$host}{$pathWithQuery}", [
            'method' => $method,
            'timeout' => 30,
            'sslverify' => true,
            'redirection' => 0,
            'headers' => $headers,
            'body' => $rawBody,
        ]);

        if (is_wp_error($response)) {
            throw new TransportException(
                "Request to {$host}{$pathWithQuery} failed: " . $response->get_error_message(),
            );
        }

        return new HttpResponse(
            status: (int) wp_remote_retrieve_response_code($response),
            body: (string) wp_remote_retrieve_body($response),
        );
    }

    /**
     * Encoded once: that exact byte string is both signed and sent.
     *
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

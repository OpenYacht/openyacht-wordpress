<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

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
    public function __construct(private readonly Signer $signer)
    {
    }

    public function get(Partner $partner, string $pathWithQuery): HttpResponse
    {
        return $this->send($partner, 'GET', $pathWithQuery, null);
    }

    public function post(Partner $partner, string $pathWithQuery, array $payload): HttpResponse
    {
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->send($partner, 'POST', $pathWithQuery, $rawBody);
    }

    private function send(Partner $partner, string $method, string $pathWithQuery, ?string $rawBody): HttpResponse
    {
        $headers = $this->signer->headers($method, $pathWithQuery, $partner->domain, $rawBody ?? '');

        if ($rawBody !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        $response = wp_remote_request("https://{$partner->domain}{$pathWithQuery}", [
            'method' => $method,
            'timeout' => 30,
            'sslverify' => true,
            'headers' => $headers,
            'body' => $rawBody,
        ]);

        if (is_wp_error($response)) {
            throw new TransportException(
                "Request to {$partner->domain}{$pathWithQuery} failed: " . $response->get_error_message(),
            );
        }

        return new HttpResponse(
            status: (int) wp_remote_retrieve_response_code($response),
            body: (string) wp_remote_retrieve_body($response),
        );
    }
}

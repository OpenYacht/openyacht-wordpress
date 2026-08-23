<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Fetches and validates a partner's discovery document.
 *
 * Always HTTPS with strict TLS verification — a well-known document or any
 * federation traffic over plain HTTP or invalid TLS is never accepted
 * (FP-2).
 *
 * // federation-protocol.md §Discovery: the well-known endpoint
 */
class WellKnownClient
{
    /**
     * @return array<string, mixed> the validated discovery document
     *
     * @throws InvalidWellKnownDocument
     */
    public function fetch(string $domain): array
    {
        $response = wp_remote_get("https://{$domain}/.well-known/openyacht", [
            'timeout' => 15,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            throw new InvalidWellKnownDocument(
                "Could not fetch the well-known document for [{$domain}]: " . $response->get_error_message(),
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        if ($status < 200 || $status >= 300) {
            throw new InvalidWellKnownDocument(
                "Could not fetch the well-known document for [{$domain}]: HTTP {$status}.",
            );
        }

        $document = json_decode((string) wp_remote_retrieve_body($response), true);

        if (! is_array($document)) {
            throw new InvalidWellKnownDocument("The well-known document for [{$domain}] is not valid JSON.");
        }

        $this->validate($domain, $document);

        return $document;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function validate(string $domain, array $document): void
    {
        $keys = $document['keys'] ?? null;
        $keysAreValid = is_array($keys) && $keys !== [];

        foreach (is_array($keys) ? $keys : [] as $key) {
            if (! is_array($key) || ! is_string($key['key_id'] ?? null) || ! is_string($key['public_key'] ?? null)) {
                $keysAreValid = false;
                break;
            }
        }

        if (! is_string($document['openyacht'] ?? null)
            || ! is_string($document['node']['uuid'] ?? null)
            || ! $keysAreValid) {
            throw new InvalidWellKnownDocument(
                "The well-known document for [{$domain}] is missing required fields (openyacht, node.uuid, keys).",
            );
        }
    }
}

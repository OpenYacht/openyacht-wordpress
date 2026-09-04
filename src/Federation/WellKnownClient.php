<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

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
    public function __construct(private readonly OutboundUrlGuard $guard = new OutboundUrlGuard())
    {
    }

    /**
     * @return array<string, mixed> the validated discovery document
     *
     * @throws InvalidWellKnownDocument
     */
    public function fetch(string $domain): array
    {
        // The domain can arrive from an unauthenticated header on first
        // contact, so validate it before any outbound request (SSRF), and
        // never follow a redirect that could bounce the trust-anchor fetch
        // to a private host or plain HTTP (FP-2, FP-14).
        try {
            $this->guard->assertPublicHost($domain);
        } catch (BlockedOutboundHost $e) {
            throw new InvalidWellKnownDocument(
                "Refusing to fetch the well-known document for [{$domain}]: " . $e->getMessage(),
                0,
                $e,
            );
        }

        $response = wp_remote_get("https://{$domain}/.well-known/openyacht", [
            'timeout' => 15,
            'sslverify' => true,
            'redirection' => 0,
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
            if (! $this->keyIsValid($key)) {
                $keysAreValid = false;
                break;
            }
        }

        if (! is_string($document['openyacht'] ?? null)
            || ! is_string($document['node']['uuid'] ?? null)
            || ! $keysAreValid) {
            throw new InvalidWellKnownDocument(
                "The well-known document for [{$domain}] is missing or has malformed required fields (openyacht, node.uuid, keys).",
            );
        }
    }

    /**
     * A key entry is trustworthy only if its material is a real Ed25519
     * public key AND its key_id is the FP-3 digest of that material
     * (substr(sha256(raw_public_key), 0, 16)). Binding the id to the key is
     * what makes key pinning meaningful: without it a pin is a pin on an
     * attacker-chosen label, and a hostile document could relabel its own
     * key with a pinned id to slip past the verifier (FP-3, FP-12).
     *
     * @param mixed $key
     */
    private function keyIsValid(mixed $key): bool
    {
        if (! is_array($key)
            || ! is_string($key['key_id'] ?? null)
            || ! is_string($key['public_key'] ?? null)
            || ($key['algorithm'] ?? 'ed25519') !== 'ed25519') {
            return false;
        }

        $rawPublicKey = base64_decode($key['public_key'], true);

        if ($rawPublicKey === false || strlen($rawPublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        return hash_equals(KeyManager::deriveKeyId($rawPublicKey), $key['key_id']);
    }
}

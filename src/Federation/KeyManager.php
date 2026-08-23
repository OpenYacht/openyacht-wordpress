<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Generates and manages the node's Ed25519 federation keys.
 *
 * // federation-protocol.md §Keys, §Key Rotation
 */
final class KeyManager
{
    public function __construct(private readonly KeyRepository $keys)
    {
    }

    /**
     * Generate a fresh Ed25519 keypair and store it with the given status.
     */
    public function generate(KeyStatus $status = KeyStatus::Active): FederationKey
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $key = new FederationKey(
            keyId: self::deriveKeyId($publicKey),
            publicKey: base64_encode($publicKey),
            privateKey: base64_encode($secretKey),
            status: $status,
            createdAt: gmdate('Y-m-d H:i:s'),
        );

        $this->keys->save($key);

        return $key;
    }

    public function ensureActiveKey(): void
    {
        if (! $this->keys->hasActiveKey()) {
            $this->generate();
        }
    }

    /**
     * The key currently used to sign outbound requests.
     */
    public function activeKey(): ?FederationKey
    {
        return $this->keys->activeKey();
    }

    /**
     * Keys published in the well-known document: active and retiring, so
     * routine rotation can overlap. Revoked keys are never published.
     *
     * @return list<FederationKey>
     */
    public function publishedKeys(): array
    {
        return $this->keys->publishedKeys();
    }

    /**
     * Routine rotation: generate a new active key and keep the old one
     * published as retiring for the overlap window (RECOMMENDED: 48 hours).
     */
    public function rotate(): FederationKey
    {
        $this->keys->markActiveKeysRetiring();

        return $this->generate();
    }

    /**
     * Revoke retiring keys once the rotation overlap window has passed,
     * removing them from the well-known document.
     */
    public function retireOverlappedKeys(): int
    {
        return $this->keys->revokeRetiringKeys();
    }

    /**
     * Emergency rotation: revoke every existing key immediately, without an
     * overlap window, and start signing with a fresh keypair. Partners'
     * next verification fails, triggers a well-known refetch, and recovers.
     */
    public function rotateEmergency(): FederationKey
    {
        $this->keys->revokeAllKeys();

        return $this->generate();
    }

    /**
     * Key ID derivation: the first 16 hex characters of the SHA-256 hash of
     * the raw 32-byte public key (FP-3).
     */
    public static function deriveKeyId(string $rawPublicKey): string
    {
        return substr(hash('sha256', $rawPublicKey), 0, 16);
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

use RuntimeException;

/**
 * One federation signing key. The private key is present (base64 of the raw
 * 64-byte sodium secret key) only when loaded for signing; keys loaded for
 * publication carry null so the secret never leaves storage unnecessarily.
 */
final class FederationKey
{
    public function __construct(
        public readonly string $keyId,
        public readonly string $publicKey,
        public readonly ?string $privateKey,
        public readonly KeyStatus $status,
        public readonly ?string $createdAt = null,
    ) {
    }

    /**
     * The raw 64-byte sodium secret key.
     */
    public function rawSecretKey(): string
    {
        if ($this->privateKey === null) {
            throw new RuntimeException('This key was loaded without its private half.');
        }

        $raw = base64_decode($this->privateKey, true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('Stored private key is not a valid sodium secret key.');
        }

        return $raw;
    }
}

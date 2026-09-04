<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use RuntimeException;

/**
 * Encryption at rest for private keys (FP-4).
 *
 * WordPress has no APP_KEY equivalent, so the secret is derived (HKDF) from
 * the wp-config.php auth salts. Each ciphertext carries a fingerprint of
 * the derived secret: if the salts are ever rotated, decryption fails
 * loudly with SaltsRotatedException — never silently — and the documented
 * recovery is an emergency key rotation. The limits of salts-in-config as
 * a key store on shared hosting are documented rather than papered over.
 */
final class KeyEncryption
{
    private const VERSION = 'v1';

    private const HKDF_INFO = 'openyacht-federation-key-encryption';

    private ?string $secret = null;

    public function __construct(private readonly string $keyMaterial)
    {
    }

    public static function fromWpSalts(): self
    {
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY'] as $saltConstant) {
            if (! defined($saltConstant) || ! is_string(constant($saltConstant)) || constant($saltConstant) === '') {
                throw new RuntimeException("wp-config.php must define {$saltConstant} for federation key encryption.");
            }
        }

        return new self(constant('AUTH_KEY') . constant('SECURE_AUTH_KEY'));
    }

    public function fingerprint(): string
    {
        return substr(hash('sha256', 'fingerprint:' . $this->secret()), 0, 16);
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->secret());

        return self::VERSION . ':' . $this->fingerprint() . ':' . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $stored): string
    {
        $parts = explode(':', $stored, 3);

        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            throw new RuntimeException('Unrecognised encrypted key format.');
        }

        if (! hash_equals($this->fingerprint(), $parts[1])) {
            throw new SaltsRotatedException();
        }

        $raw = base64_decode($parts[2], true);

        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Encrypted key payload is malformed.');
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->secret());

        if ($plaintext === false) {
            throw new RuntimeException('Federation key decryption failed.');
        }

        return $plaintext;
    }

    private function secret(): string
    {
        return $this->secret ??= hash_hkdf('sha256', $this->keyMaterial, 32, self::HKDF_INFO);
    }
}

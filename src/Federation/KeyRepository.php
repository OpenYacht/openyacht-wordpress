<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Storage boundary for federation keys. The wpdb implementation encrypts
 * private keys at rest; unit tests substitute an in-memory double.
 */
interface KeyRepository
{
    public function save(FederationKey $key): void;

    public function hasActiveKey(): bool;

    /**
     * The key currently used to sign outbound requests, private half
     * decrypted and included.
     */
    public function activeKey(): ?FederationKey;

    /**
     * Keys published in the well-known document: active and retiring,
     * newest first, public halves only. Revoked keys are never published.
     *
     * @return list<FederationKey>
     */
    public function publishedKeys(): array;

    public function markActiveKeysRetiring(): void;

    public function revokeRetiringKeys(): int;

    public function revokeAllKeys(): void;
}

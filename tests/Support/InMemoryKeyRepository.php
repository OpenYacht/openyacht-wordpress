<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\FederationKey;
use OpenYacht\Federation\KeyRepository;
use OpenYacht\Federation\KeyStatus;

final class InMemoryKeyRepository implements KeyRepository
{
    /** @var list<FederationKey> */
    public array $keys = [];

    public function save(FederationKey $key): void
    {
        $this->keys[] = $key;
    }

    public function hasActiveKey(): bool
    {
        return $this->activeKey() !== null;
    }

    public function activeKey(): ?FederationKey
    {
        foreach (array_reverse($this->keys) as $key) {
            if ($key->status === KeyStatus::Active) {
                return $key;
            }
        }

        return null;
    }

    public function publishedKeys(): array
    {
        return array_values(array_filter(
            array_reverse($this->keys),
            static fn (FederationKey $key): bool => $key->status !== KeyStatus::Revoked,
        ));
    }

    public function markActiveKeysRetiring(): void
    {
        $this->transition(KeyStatus::Active, KeyStatus::Retiring);
    }

    public function revokeRetiringKeys(): int
    {
        return $this->transition(KeyStatus::Retiring, KeyStatus::Revoked);
    }

    public function revokeAllKeys(): void
    {
        $this->transition(KeyStatus::Active, KeyStatus::Revoked);
        $this->transition(KeyStatus::Retiring, KeyStatus::Revoked);
    }

    private function transition(KeyStatus $from, KeyStatus $to): int
    {
        $count = 0;

        foreach ($this->keys as $i => $key) {
            if ($key->status === $from) {
                $this->keys[$i] = new FederationKey(
                    keyId: $key->keyId,
                    publicKey: $key->publicKey,
                    privateKey: $key->privateKey,
                    status: $to,
                    createdAt: $key->createdAt,
                );
                $count++;
            }
        }

        return $count;
    }
}

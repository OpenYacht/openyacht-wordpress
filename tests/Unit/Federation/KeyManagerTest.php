<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use OpenYacht\Federation\KeyManager;
use OpenYacht\Federation\KeyStatus;
use OpenYacht\Tests\Support\InMemoryKeyRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('FP-3')]
final class KeyManagerTest extends TestCase
{
    public function testGenerateStoresAWorkingKeypairWithDerivedKeyId(): void
    {
        $repository = new InMemoryKeyRepository();
        $key = (new KeyManager($repository))->generate();

        $rawPublic = base64_decode($key->publicKey, true);
        self::assertIsString($rawPublic);
        self::assertSame(KeyManager::deriveKeyId($rawPublic), $key->keyId);
        self::assertSame(16, strlen($key->keyId));
        self::assertSame(KeyStatus::Active, $key->status);
        self::assertCount(1, $repository->keys);

        // The stored pair actually signs and verifies.
        $signature = sodium_crypto_sign_detached('probe', $key->rawSecretKey());
        self::assertTrue(sodium_crypto_sign_verify_detached($signature, 'probe', $rawPublic));
    }

    public function testEnsureActiveKeyIsIdempotent(): void
    {
        $repository = new InMemoryKeyRepository();
        $manager = new KeyManager($repository);

        $manager->ensureActiveKey();
        $manager->ensureActiveKey();

        self::assertCount(1, $repository->keys);
    }

    public function testRoutineRotationKeepsTheOldKeyPublishedAsRetiring(): void
    {
        $repository = new InMemoryKeyRepository();
        $manager = new KeyManager($repository);

        $old = $manager->generate();
        $new = $manager->rotate();

        $published = $manager->publishedKeys();
        $byId = array_column(array_map(static fn ($k) => ['id' => $k->keyId, 'status' => $k->status], $published), 'status', 'id');

        self::assertCount(2, $published);
        self::assertSame(KeyStatus::Active, $byId[$new->keyId]);
        self::assertSame(KeyStatus::Retiring, $byId[$old->keyId]);

        $manager->retireOverlappedKeys();

        self::assertCount(1, $manager->publishedKeys());
    }

    public function testEmergencyRotationRevokesEverythingImmediately(): void
    {
        $repository = new InMemoryKeyRepository();
        $manager = new KeyManager($repository);

        $manager->generate();
        $manager->rotate();
        $fresh = $manager->rotateEmergency();

        $published = $manager->publishedKeys();

        self::assertCount(1, $published);
        self::assertSame($fresh->keyId, $published[0]->keyId);
    }
}

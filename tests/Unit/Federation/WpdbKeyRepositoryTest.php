<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use OpenYacht\Federation\FederationKey;
use OpenYacht\Federation\KeyEncryption;
use OpenYacht\Federation\KeyStatus;
use OpenYacht\Federation\WpdbKeyRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('FP-4')]
final class WpdbKeyRepositoryTest extends TestCase
{
    public function testPrivateKeyIsEncryptedAtRest(): void
    {
        $wpdb = new \wpdb();
        $encryption = new KeyEncryption('salt-material');
        $repository = new WpdbKeyRepository($wpdb, $encryption);

        $secret = base64_encode(random_bytes(64));
        $repository->save(new FederationKey(
            keyId: str_repeat('a', 16),
            publicKey: base64_encode(random_bytes(32)),
            privateKey: $secret,
            status: KeyStatus::Active,
        ));

        self::assertCount(1, $wpdb->inserted);
        $stored = (string) $wpdb->inserted[0]['data']['private_key'];

        self::assertStringNotContainsString($secret, $stored, 'DB row must not contain the plaintext secret');
        self::assertStringStartsWith('v1:', $stored);
        self::assertSame($secret, $encryption->decrypt($stored));
    }

    public function testActiveKeyDecryptsThePrivateHalf(): void
    {
        $wpdb = new \wpdb();
        $encryption = new KeyEncryption('salt-material');
        $repository = new WpdbKeyRepository($wpdb, $encryption);
        $secret = base64_encode(random_bytes(64));

        $wpdb->row = [
            'key_id' => str_repeat('b', 16),
            'public_key' => base64_encode(random_bytes(32)),
            'private_key' => $encryption->encrypt($secret),
            'status' => 'active',
            'created_at' => '2026-08-23 12:00:00',
        ];

        $key = $repository->activeKey();

        self::assertNotNull($key);
        self::assertSame($secret, $key->privateKey);
    }

    public function testPublishedKeysNeverCarryThePrivateHalf(): void
    {
        $wpdb = new \wpdb();
        $repository = new WpdbKeyRepository($wpdb, new KeyEncryption('salt-material'));

        $wpdb->results = [[
            'key_id' => str_repeat('c', 16),
            'public_key' => base64_encode(random_bytes(32)),
            'status' => 'active',
            'created_at' => '2026-08-23 12:00:00',
        ]];

        $keys = $repository->publishedKeys();

        self::assertCount(1, $keys);
        self::assertNull($keys[0]->privateKey);
    }
}

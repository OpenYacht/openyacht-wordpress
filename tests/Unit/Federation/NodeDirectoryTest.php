<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use DateTimeImmutable;
use InvalidArgumentException;
use OpenYacht\Federation\KeyManager;
use OpenYacht\Federation\NodeDirectory;
use OpenYacht\Tests\Support\InMemoryKeyRepository;
use OpenYacht\Tests\Unit\TestCase;

final class NodeDirectoryTest extends TestCase
{
    private KeyManager $keys;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('home_url')->justReturn('https://sender.example/');
        Functions\when('apply_filters')->returnArg(2);
        $this->keys = new KeyManager(new InMemoryKeyRepository());
        $this->keys->ensureActiveKey();
    }

    public function testListingRequestMatchesTheSpecTokenFormatAndVerifies(): void
    {
        $request = (new NodeDirectory($this->keys))
            ->listingRequest('list', new DateTimeImmutable('2026-08-23T18:00:00Z'));

        self::assertSame('openyacht-node-listing:v1:sender.example:list:2026-08-23', $request['token']);

        // Verified the way the directory maintainer does: the signature
        // must check out against a currently published key, no key ID.
        $verified = false;

        foreach ($this->keys->publishedKeys() as $key) {
            $verified = $verified || sodium_crypto_sign_verify_detached(
                (string) base64_decode($request['signature'], true),
                $request['token'],
                (string) base64_decode($key->publicKey, true),
            );
        }

        self::assertTrue($verified, 'signature verifies against a published key');
    }

    public function testDelistAndAmendAreValidActionsAndAnythingElseIsNot(): void
    {
        $directory = new NodeDirectory($this->keys);
        $date = new DateTimeImmutable('2026-08-23T00:00:00Z');

        self::assertStringContainsString(':delist:', $directory->listingRequest('delist', $date)['token']);
        self::assertStringContainsString(':amend:', $directory->listingRequest('amend', $date)['token']);

        $this->expectException(InvalidArgumentException::class);
        $directory->listingRequest('relist', $date);
    }
}

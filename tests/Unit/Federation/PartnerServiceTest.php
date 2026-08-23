<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use OpenYacht\Federation\PartnerService;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Federation\WellKnownClient;
use OpenYacht\Tests\Support\CollectingLogger;
use OpenYacht\Tests\Support\InMemoryPartnerRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class PartnerServiceTest extends TestCase
{
    private InMemoryPartnerRepository $partners;

    private CollectingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partners = new InMemoryPartnerRepository();
        $this->logger = new CollectingLogger();
    }

    /**
     * @param array<string, mixed> $document
     */
    private function service(array $document): PartnerService
    {
        $client = Mockery::mock(WellKnownClient::class);
        $client->shouldReceive('fetch')->andReturn($document);

        return new PartnerService($this->partners, $client, $this->logger);
    }

    /**
     * @return array<string, mixed>
     */
    private function wellKnown(string $uuid = 'aaaaaaaa-1111-2222-3333-444444444444'): array
    {
        return [
            'openyacht' => '1.0',
            'node' => ['uuid' => $uuid, 'name' => 'Partner', 'software' => null, 'website' => null],
            'keys' => [['key_id' => str_repeat('a', 16), 'algorithm' => 'ed25519', 'public_key' => base64_encode(random_bytes(32)), 'created_at' => '2026-08-23T00:00:00Z']],
        ];
    }

    #[Group('FP-13')]
    public function testTofuAddStoresIdentityAndStartsProvisional(): void
    {
        Actions\expectDone('openyacht_partner_added')->once();

        $partner = $this->service($this->wellKnown())->add('Broker.Example ');

        self::assertSame('broker.example', $partner->domain);
        self::assertSame(TrustLevel::Provisional, $partner->trustLevel);
        self::assertSame('aaaaaaaa-1111-2222-3333-444444444444', $partner->nodeUuid);
        self::assertCount(1, $partner->publishedKeys());
    }

    public function testAddRefusesADuplicateDomain(): void
    {
        $service = $this->service($this->wellKnown());
        $service->add('broker.example');

        $this->expectException(RuntimeException::class);

        $service->add('broker.example');
    }

    #[Group('FP-12')]
    public function testOutOfBandRegistrationPinsTheDerivedKeyId(): void
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = base64_encode(sodium_crypto_sign_publickey($keypair));
        $expectedKeyId = substr(hash('sha256', sodium_crypto_sign_publickey($keypair)), 0, 16);

        $service = new PartnerService($this->partners, Mockery::mock(WellKnownClient::class), $this->logger);
        $partner = $service->addWithKey('ref-app.test', $publicKey, 'bbbbbbbb-1111-2222-3333-444444444444');

        self::assertSame($expectedKeyId, $partner->pinnedKeyId);
        self::assertSame([$expectedKeyId => $publicKey], $partner->publishedKeys());
        self::assertSame(TrustLevel::Provisional, $partner->trustLevel);
    }

    public function testOutOfBandRegistrationRejectsAMalformedKey(): void
    {
        $service = new PartnerService($this->partners, Mockery::mock(WellKnownClient::class), $this->logger);

        $this->expectException(RuntimeException::class);

        $service->addWithKey('ref-app.test', 'not-base64!');
    }

    #[Group('FP-11')]
    public function testChangedNodeUuidDowngradesToProvisionalAndClearsApproval(): void
    {
        $service = $this->service($this->wellKnown('cccccccc-9999-8888-7777-666666666666'));
        $partner = $this->partners->insert([
            'domain' => 'broker.example',
            'node_uuid' => 'aaaaaaaa-1111-2222-3333-444444444444',
            'trust_level' => TrustLevel::Verified,
            'approved_by_user_id' => 7,
        ]);

        Actions\expectDone('openyacht_partner_uuid_changed')->once();

        $refreshed = $service->refreshKeys($partner);

        self::assertSame(TrustLevel::Provisional, $refreshed->trustLevel);
        self::assertNull($refreshed->approvedByUserId);
        self::assertSame('cccccccc-9999-8888-7777-666666666666', $refreshed->nodeUuid);
        self::assertNotEmpty(array_filter($this->logger->entries, static fn (array $entry): bool => $entry['outcome'] === 'partner_uuid_changed'));
    }

    #[Group('FP-11')]
    public function testUnchangedUuidJustRefreshesKeys(): void
    {
        $service = $this->service($this->wellKnown());
        $partner = $this->partners->insert([
            'domain' => 'broker.example',
            'node_uuid' => 'aaaaaaaa-1111-2222-3333-444444444444',
            'trust_level' => TrustLevel::Verified,
            'approved_by_user_id' => 7,
        ]);

        $refreshed = $service->refreshKeys($partner);

        self::assertSame(TrustLevel::Verified, $refreshed->trustLevel);
        self::assertSame(7, $refreshed->approvedByUserId);
        self::assertNotNull($refreshed->keysFetchedAt);
    }

    #[Group('FP-13')]
    #[Group('FP-9')]
    public function testApproveAndBlockTransitions(): void
    {
        Functions\when('do_action')->justReturn(null);
        $service = $this->service($this->wellKnown());
        $partner = $service->add('broker.example');

        $approved = $service->approve($partner, 3);
        self::assertSame(TrustLevel::Verified, $approved->trustLevel);
        self::assertSame(3, $approved->approvedByUserId);

        $blocked = $service->block($approved);
        self::assertSame(TrustLevel::Blocked, $blocked->trustLevel);
        self::assertSame([], $this->partners->syncable(), 'blocked partners are never synced');
    }
}

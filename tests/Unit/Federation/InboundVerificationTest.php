<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery;
use OpenYacht\Federation\ErrorCode;
use OpenYacht\Federation\FederationKey;
use OpenYacht\Federation\InboundVerification;
use OpenYacht\Federation\InvalidWellKnownDocument;
use OpenYacht\Federation\KeyManager;
use OpenYacht\Federation\KeyStatus;
use OpenYacht\Federation\PartnerService;
use OpenYacht\Federation\Signer;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Federation\Verifier;
use OpenYacht\Federation\WellKnownClient;
use OpenYacht\Http\RequestContext;
use OpenYacht\Tests\Support\CollectingLogger;
use OpenYacht\Tests\Support\InMemoryKeyRepository;
use OpenYacht\Tests\Support\InMemoryPartnerRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

final class InboundVerificationTest extends TestCase
{
    private const SENDER = 'sender.example';

    private const RECEIVER = 'node.test';

    private InMemoryPartnerRepository $partners;

    private CollectingLogger $logger;

    /** @var Mockery\MockInterface&WellKnownClient */
    private $wellKnown;

    private FederationKey $senderKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partners = new InMemoryPartnerRepository();
        $this->logger = new CollectingLogger();
        $this->wellKnown = Mockery::mock(WellKnownClient::class);
        $this->senderKey = $this->makeKey();

        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('home_url')->justReturn('https://' . self::SENDER . '/');
    }

    private function makeKey(): FederationKey
    {
        $keypair = sodium_crypto_sign_keypair();

        return new FederationKey(
            keyId: KeyManager::deriveKeyId(sodium_crypto_sign_publickey($keypair)),
            publicKey: base64_encode(sodium_crypto_sign_publickey($keypair)),
            privateKey: base64_encode(sodium_crypto_sign_secretkey($keypair)),
            status: KeyStatus::Active,
        );
    }

    private function middleware(): InboundVerification
    {
        // home_url is stubbed to the SENDER so the test Signer signs as the
        // sender; the receiver's own identity is passed explicitly.
        return new InboundVerification(
            new Verifier(),
            $this->partners,
            new PartnerService($this->partners, $this->wellKnown, $this->logger, null, self::RECEIVER),
            $this->logger,
            self::RECEIVER,
        );
    }

    /**
     * A correctly signed request from the sender, as we would receive it.
     */
    private function signedRequest(?FederationKey $key = null, string $path = '/openyacht/v1/listings?page_size=5'): RequestContext
    {
        $signer = new Signer(new KeyManager(new InMemoryKeyRepository()));
        $headers = $signer->headers('GET', $path, self::RECEIVER, '', $key ?? $this->senderKey);

        return new RequestContext('GET', $path, self::RECEIVER, '', array_change_key_case($headers, CASE_LOWER));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function storedPartner(array $overrides = []): void
    {
        $this->partners->insert($overrides + [
            'domain' => self::SENDER,
            'node_uuid' => 'aaaaaaaa-1111-2222-3333-444444444444',
            'keys_json' => [['key_id' => $this->senderKey->keyId, 'public_key' => $this->senderKey->publicKey]],
            'trust_level' => TrustLevel::Verified,
        ]);
    }

    public function testMissingHeadersAreRejected(): void
    {
        $result = $this->middleware()->authenticate(new RequestContext('GET', '/openyacht/v1/listings', self::RECEIVER, '', []));

        self::assertSame(ErrorCode::SignatureInvalid, $result->error);
    }

    public function testARequestClaimingToBeThisNodeIsRejectedBeforeFirstContact(): void
    {
        // Otherwise an impostor presenting our own domain would be TOFU
        // auto-registered as our provisional self-partner.
        $this->wellKnown->shouldNotReceive('fetch');
        $signed = $this->signedRequest();
        $headers = ['x-openyacht-node' => self::RECEIVER] + $this->headersOf($signed);

        $result = $this->middleware()->authenticate(new RequestContext('GET', $signed->pathWithQuery, self::RECEIVER, '', $headers));

        self::assertSame(ErrorCode::SignatureInvalid, $result->error);
        self::assertNull($this->partners->findByDomain(self::RECEIVER), 'no self-partner was registered');
    }

    /**
     * @return array<string, string>
     */
    private function headersOf(RequestContext $request): array
    {
        $headers = [];

        foreach (['X-OpenYacht-Node', 'X-OpenYacht-Key', 'X-OpenYacht-Timestamp', 'X-OpenYacht-Signature'] as $name) {
            $headers[strtolower($name)] = $request->header($name);
        }

        return $headers;
    }

    #[Group('FP-13')]
    public function testKnownVerifiedPartnerWithValidSignaturePasses(): void
    {
        $this->storedPartner();

        $result = $this->middleware()->authenticate($this->signedRequest());

        self::assertTrue($result->verified());
        self::assertSame(self::SENDER, $result->partner?->domain);
    }

    #[Group('FP-13')]
    public function testUnknownSenderWithUnreachableWellKnownIsPartnerUnknown(): void
    {
        $this->wellKnown->shouldReceive('fetch')->andThrow(new InvalidWellKnownDocument('unreachable'));

        $result = $this->middleware()->authenticate($this->signedRequest());

        self::assertSame(ErrorCode::PartnerUnknown, $result->error);
    }

    #[Group('FP-13')]
    public function testFirstContactIsStoredProvisionalAndGatedFromListings(): void
    {
        $this->wellKnown->shouldReceive('fetch')->andReturn([
            'openyacht' => '1.0',
            'node' => ['uuid' => 'bbbbbbbb-1111-2222-3333-444444444444'],
            'keys' => [['key_id' => $this->senderKey->keyId, 'public_key' => $this->senderKey->publicKey]],
        ]);

        $result = $this->middleware()->authenticate($this->signedRequest());

        self::assertSame(ErrorCode::PartnerProvisional, $result->error, 'authenticated but unapproved: no listings');
        self::assertSame(TrustLevel::Provisional, $this->partners->findByDomain(self::SENDER)?->trustLevel);

        $allowed = $this->middleware()->authenticate($this->signedRequest(path: '/openyacht/v1/partners/request'));
        self::assertSame(ErrorCode::PartnerProvisional, $allowed->error);
    }

    #[Group('FP-13')]
    public function testFirstContactActionIsLeftToACallerThatDefersItOnAVerifiedRequest(): void
    {
        // The partnership-request endpoint stores the sender's message
        // first and fires the action itself, so the notification carries
        // the message; the result tells it that it owes the action.
        Actions\expectDone('openyacht_partner_first_contact')->never();
        $this->wellKnown->shouldReceive('fetch')->andReturn($this->senderWellKnown());

        $result = $this->middleware()->authenticate(
            $this->signedRequest(path: '/openyacht/v1/partners/request'),
            allowProvisional: true,
            deferFirstContact: true,
        );

        self::assertTrue($result->verified());
        self::assertTrue($result->firstContact);
    }

    #[Group('FP-13')]
    public function testDeferredFirstContactStillFiresWhenTheRequestIsRejected(): void
    {
        // The sender was registered whatever the request's fate, so the
        // administrator must still hear about it — a deferring caller
        // never sees a rejected request, so it cannot fire it.
        Actions\expectDone('openyacht_partner_first_contact')->once();
        $this->wellKnown->shouldReceive('fetch')->andReturn($this->senderWellKnown());

        // Signed with a key the sender's well-known does not publish.
        $result = $this->middleware()->authenticate(
            $this->signedRequest($this->makeKey(), '/openyacht/v1/partners/request'),
            allowProvisional: true,
            deferFirstContact: true,
        );

        self::assertFalse($result->verified());
        self::assertNotNull($this->partners->findByDomain(self::SENDER), 'registered by trust on first use regardless');
    }

    #[Group('FP-13')]
    public function testFirstContactFiresImmediatelyWhenNotDeferred(): void
    {
        Actions\expectDone('openyacht_partner_first_contact')->once();
        $this->wellKnown->shouldReceive('fetch')->andReturn($this->senderWellKnown());

        $result = $this->middleware()->authenticate($this->signedRequest(), allowProvisional: true);

        self::assertTrue($result->verified());
        self::assertTrue($result->firstContact);
    }

    public function testAKnownSenderIsNotFirstContact(): void
    {
        $this->storedPartner();

        $result = $this->middleware()->authenticate($this->signedRequest());

        self::assertTrue($result->verified());
        self::assertFalse($result->firstContact);
    }

    /**
     * @return array<string, mixed>
     */
    private function senderWellKnown(): array
    {
        return [
            'openyacht' => '1.0',
            'node' => ['uuid' => 'bbbbbbbb-1111-2222-3333-444444444444'],
            'keys' => [['key_id' => $this->senderKey->keyId, 'public_key' => $this->senderKey->publicKey]],
        ];
    }

    public function testProvisionalPartnerIsAllowedWhereExplicitlyPermitted(): void
    {
        $this->storedPartner(['trust_level' => TrustLevel::Provisional]);

        $result = $this->middleware()->authenticate($this->signedRequest(), allowProvisional: true);

        self::assertTrue($result->verified());
    }

    #[Group('FP-9')]
    public function testBlockedPartnerIsRejectedEvenWithAValidSignature(): void
    {
        $this->storedPartner(['trust_level' => TrustLevel::Blocked]);

        $result = $this->middleware()->authenticate($this->signedRequest());

        self::assertSame(ErrorCode::PartnerBlocked, $result->error);
    }

    #[Group('FP-12')]
    public function testNonPinnedKeyIsRejectedEvenIfPublished(): void
    {
        $otherKey = $this->makeKey();
        $this->storedPartner([
            'keys_json' => [
                ['key_id' => $this->senderKey->keyId, 'public_key' => $this->senderKey->publicKey],
                ['key_id' => $otherKey->keyId, 'public_key' => $otherKey->publicKey],
            ],
            'pinned_key_id' => $this->senderKey->keyId,
        ]);

        $result = $this->middleware()->authenticate($this->signedRequest($otherKey));

        self::assertSame(ErrorCode::SignatureInvalid, $result->error);
    }

    #[Group('FP-10')]
    public function testRotatedKeyRecoversViaTheSingleRefetch(): void
    {
        $freshKey = $this->makeKey();
        $this->storedPartner(); // still holds the OLD key only
        $this->wellKnown->shouldReceive('fetch')->once()->andReturn([
            'openyacht' => '1.0',
            'node' => ['uuid' => 'aaaaaaaa-1111-2222-3333-444444444444'],
            'keys' => [['key_id' => $freshKey->keyId, 'public_key' => $freshKey->publicKey]],
        ]);

        $result = $this->middleware()->authenticate($this->signedRequest($freshKey));

        self::assertTrue($result->verified(), 'verification recovers after the one permitted refetch');
    }

    #[Group('FP-10')]
    public function testRefetchIsRateLimited(): void
    {
        Functions\when('get_transient')->justReturn(1); // refetch already used this window
        $this->storedPartner();
        $this->wellKnown->shouldReceive('fetch')->never();

        $result = $this->middleware()->authenticate($this->signedRequest($this->makeKey()));

        self::assertSame(ErrorCode::SignatureInvalid, $result->error);
    }

    #[Group('FP-11')]
    public function testUuidChangeDuringRefetchRejectsAndDowngrades(): void
    {
        $freshKey = $this->makeKey();
        $this->storedPartner();
        $this->wellKnown->shouldReceive('fetch')->once()->andReturn([
            'openyacht' => '1.0',
            'node' => ['uuid' => 'ffffffff-9999-8888-7777-666666666666'],
            'keys' => [['key_id' => $freshKey->keyId, 'public_key' => $freshKey->publicKey]],
        ]);

        $result = $this->middleware()->authenticate($this->signedRequest($freshKey));

        self::assertSame(ErrorCode::SignatureInvalid, $result->error);
        self::assertSame(TrustLevel::Provisional, $this->partners->findByDomain(self::SENDER)?->trustLevel, 'downgraded pending re-approval');
    }

    #[Group('FP-8')]
    public function testOutOfWindowTimestampRejectsWithoutRefetching(): void
    {
        $this->storedPartner();
        $this->wellKnown->shouldReceive('fetch')->never();

        $signer = new Signer(new KeyManager(new InMemoryKeyRepository()));
        $path = '/openyacht/v1/listings';
        $old = new \DateTimeImmutable('-2 hours', new \DateTimeZone('UTC'));
        $headers = $signer->headers('GET', $path, self::RECEIVER, '', $this->senderKey, $old);
        $request = new RequestContext('GET', $path, self::RECEIVER, '', array_change_key_case($headers, CASE_LOWER));

        $result = $this->middleware()->authenticate($request);

        self::assertSame(ErrorCode::TimestampOutOfRange, $result->error);
    }
}

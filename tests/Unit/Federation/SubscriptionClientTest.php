<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Mockery;
use OpenYacht\Federation\CapabilitiesClient;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\SubscriptionClient;
use OpenYacht\Federation\SubscriptionFailed;
use OpenYacht\Federation\TransportException;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Tests\Support\CollectingLogger;
use OpenYacht\Tests\Support\FakeFederationClient;
use OpenYacht\Tests\Support\InMemoryPartnerRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The consumer half, wire side: subscribing checks the partner's
 * capabilities first (API-7), registers this node's inbox, and never
 * subscribes to a partner whose pushes the inbox would refuse.
 */
#[Group('API-11')]
final class SubscriptionClientTest extends TestCase
{
    private InMemoryPartnerRepository $partners;

    private FakeFederationClient $client;

    private CollectingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partners = new InMemoryPartnerRepository();
        $this->client = new FakeFederationClient();
        $this->logger = new CollectingLogger();
    }

    private function subscriptions(bool $advertises = true): SubscriptionClient
    {
        $capabilities = Mockery::mock(CapabilitiesClient::class);
        $capabilities->shouldReceive('advertises')->with('authority.example', 'subscriptions')->andReturn($advertises);

        return new SubscriptionClient($this->client, $this->partners, $capabilities, $this->logger, 'node.test');
    }

    private function partner(TrustLevel $trust = TrustLevel::Verified, ?string $subscribedAt = null): Partner
    {
        return $this->partners->insert(['domain' => 'authority.example', 'trust_level' => $trust, 'push_subscribed_at' => $subscribedAt]);
    }

    #[Group('API-7')]
    public function testSubscribeRegistersTheInboxAfterTheCapabilityCheck(): void
    {
        $this->client->queueJson([], 204);
        $partner = $this->partner();

        $this->subscriptions()->subscribe($partner);

        self::assertSame([[
            'method' => 'POST',
            'path' => '/openyacht/v1/subscriptions',
            'payload' => ['callback' => 'https://node.test/openyacht/v1/inbox'],
        ]], $this->client->requests);
        self::assertNotNull($this->partners->find($partner->id)?->pushSubscribedAt);
        self::assertNotEmpty(array_filter($this->logger->entries, static fn (array $e): bool => $e['outcome'] === 'push_subscribed'));
    }

    #[Group('FP-13')]
    public function testSubscribeRefusesAPartnerNotVerifiedHere(): void
    {
        // The inbox verifies deliveries with the same middleware as a poll
        // and would 403 a provisional sender; subscribing would only
        // produce deliveries this node then rejects.
        $partner = $this->partner(TrustLevel::Provisional);

        try {
            $this->subscriptions()->subscribe($partner);
            self::fail('subscribed to an unverified partner');
        } catch (SubscriptionFailed $exception) {
            self::assertStringContainsString('not a verified partner', $exception->getMessage());
        }

        self::assertSame([], $this->client->requests, 'no request went out');
    }

    #[Group('API-7')]
    public function testAPartnerNotAdvertisingTheFeatureIsLeftToPolling(): void
    {
        $partner = $this->partner();

        try {
            $this->subscriptions(advertises: false)->subscribe($partner);
            self::fail('subscribed without the capability');
        } catch (SubscriptionFailed $exception) {
            self::assertStringContainsString('does not offer push subscriptions', $exception->getMessage());
        }

        self::assertSame([], $this->client->requests);
        self::assertNull($this->partners->find($partner->id)?->pushSubscribedAt);
    }

    public function testARefusedOrUnreachableRegistrationIsReportedAndNotStamped(): void
    {
        $partner = $this->partner();

        $this->client->queueJson(['error' => ['code' => 'PARTNER_PROVISIONAL']], 403);
        try {
            $this->subscriptions()->subscribe($partner);
            self::fail('a refusal was swallowed');
        } catch (SubscriptionFailed $exception) {
            self::assertStringContainsString('HTTP 403 PARTNER_PROVISIONAL', $exception->getMessage());
        }

        $this->client->queueFailure(new TransportException('cURL error 28: timed out'));
        try {
            $this->subscriptions()->subscribe($partner);
            self::fail('a transport failure was swallowed');
        } catch (SubscriptionFailed $exception) {
            self::assertStringContainsString('timed out', $exception->getMessage());
        }

        self::assertNull($this->partners->find($partner->id)?->pushSubscribedAt);
    }

    public function testUnsubscribeClearsTheLocalRecordWhateverThePartnerAnswers(): void
    {
        $partner = $this->partner(subscribedAt: '2026-09-01 00:00:00');

        $this->client->queueJson([], 204);
        self::assertNull($this->subscriptions()->unsubscribe($partner));
        self::assertNull($this->partners->find($partner->id)?->pushSubscribedAt);
        self::assertSame('DELETE', $this->client->requests[0]['method']);

        // A partner that no longer serves the endpoint has nothing to remove.
        $this->partners->update($partner->id, ['push_subscribed_at' => '2026-09-01 00:00:00']);
        $this->client->queueJson([], 404);
        self::assertNull($this->subscriptions()->unsubscribe($this->partners->find($partner->id)));

        // Anything else is a warning, and the record is cleared regardless:
        // the operator asked to stop, and the inbox refuses its pushes now.
        $this->partners->update($partner->id, ['push_subscribed_at' => '2026-09-01 00:00:00']);
        $this->client->queueFailure(new TransportException('unreachable'));
        $warning = $this->subscriptions()->unsubscribe($this->partners->find($partner->id));
        self::assertStringContainsString('unreachable', (string) $warning);
        self::assertNull($this->partners->find($partner->id)?->pushSubscribedAt);
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use OpenYacht\Federation\FederationClient;
use OpenYacht\Federation\HttpResponse;
use OpenYacht\Federation\Listing;
use OpenYacht\Federation\ListingSerializer;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\PushDeliveryWorker;
use OpenYacht\Federation\SharingService;
use OpenYacht\Federation\SubscriptionService;
use OpenYacht\Federation\TransportException;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Tests\Support\CollectingLogger;
use OpenYacht\Tests\Support\FakeFederationClient;
use OpenYacht\Tests\Support\InMemoryAudienceRepository;
use OpenYacht\Tests\Support\InMemoryListingMediaRepository;
use OpenYacht\Tests\Support\InMemoryListingRepository;
use OpenYacht\Tests\Support\InMemoryPartnerGroupRepository;
use OpenYacht\Tests\Support\InMemoryPartnerRepository;
use OpenYacht\Tests\Support\InMemoryPriceHistoryRepository;
use OpenYacht\Tests\Support\InMemoryPushDeliveryRepository;
use OpenYacht\Tests\Support\InMemoryVisibilityEventRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The delivery worker: signed POSTs to the callback, exponential retry
 * inside the 24-hour window, and the at-least-once bookkeeping around a
 * change that lands while a delivery is in flight.
 */
#[Group('API-10')]
final class PushDeliveryWorkerTest extends TestCase
{
    public const NOW = 1_800_000_000;

    private const CALLBACK = 'https://subscribed.example/openyacht/v1/inbox';

    private InMemoryListingRepository $listings;

    private InMemoryPartnerRepository $partners;

    private InMemoryPushDeliveryRepository $queue;

    private SubscriptionService $subscriptions;

    private FakeFederationClient $client;

    private CollectingLogger $logger;

    /** @var list<int> */
    private array $drainRequests = [];

    private Partner $partner;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('home_url')->justReturn('https://node.test/');
        Functions\when('get_bloginfo')->justReturn('Test Node');
        Functions\when('get_option')->justReturn([]);

        $audience = new InMemoryAudienceRepository();
        $events = new InMemoryVisibilityEventRepository();
        $groups = new InMemoryPartnerGroupRepository();
        $this->listings = new InMemoryListingRepository($audience, $events, $groups);
        $this->partners = new InMemoryPartnerRepository();
        $this->queue = new InMemoryPushDeliveryRepository();
        $this->logger = new CollectingLogger();
        $this->client = new FakeFederationClient();
        $sharing = new SharingService($this->listings, $this->partners, $audience, $groups, $events, $this->logger);
        $this->subscriptions = new SubscriptionService(
            $this->partners,
            $this->queue,
            $sharing,
            $events,
            new ListingSerializer(new InMemoryPriceHistoryRepository(), new InMemoryListingMediaRepository()),
            $this->logger,
            offered: true,
        );

        $this->partner = $this->partners->insert([
            'domain' => 'subscribed.example',
            'trust_level' => TrustLevel::Verified,
            'push_callback_url' => self::CALLBACK,
        ]);
        $this->listing = $this->listings->insert([
            'uuid' => 'aaaaaaaa-aaaa-4bbb-8ccc-dddddddddddd',
            'status' => ListingStatus::Active,
            'name' => 'PUSHED',
            'federation_updated_at' => '2026-08-01 10:00:00',
        ]);
    }

    private function worker(?FederationClient $client = null): PushDeliveryWorker
    {
        return new PushDeliveryWorker(
            $this->queue,
            $this->partners,
            $this->listings,
            $this->subscriptions,
            $client ?? $this->client,
            $this->logger,
            function (int $at): void {
                $this->drainRequests[] = $at;
            },
        );
    }

    private function enqueue(int $at = self::NOW): void
    {
        $this->queue->enqueue($this->partner->id, $this->listing->id, gmdate('Y-m-d H:i:s', $at));
    }

    public function testADueDeliveryIsPostedSignedToTheCallbackAndCleared(): void
    {
        $this->enqueue();
        $this->client->queueJson(['status' => 'received', 'outcome' => 'created']);

        $counts = $this->worker()->drain(self::NOW);

        self::assertSame(['delivered' => 1, 'failed' => 0, 'abandoned' => 0, 'dropped' => 0], $counts);
        self::assertSame('POST', $this->client->requests[0]['method']);
        self::assertSame(self::CALLBACK, $this->client->requests[0]['path']);
        self::assertSame('https://node.test/openyacht/v1/listings/' . $this->listing->uuid, $this->client->requests[0]['payload']['id']);
        self::assertSame([], $this->queue->all());
        self::assertNotNull($this->partners->find($this->partner->id)?->pushLastDeliveredAt);
        self::assertSame([], $this->drainRequests, 'nothing left, nothing to schedule');
    }

    public function testAFailedAttemptBacksOffExponentiallyAndAsksForAnotherRun(): void
    {
        $this->enqueue();
        $this->client->queueJson(['error' => ['code' => 'RATE_LIMITED']], 429);
        $this->client->queueFailure(new TransportException('connection refused'));

        $first = $this->worker()->drain(self::NOW);
        self::assertSame(1, $first['failed']);
        $row = $this->queue->all()[0];
        self::assertSame(1, $row->attempts);
        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW + 60), $row->nextAttemptAt);
        self::assertSame('HTTP 429', $row->lastError);
        self::assertSame([self::NOW + 60], $this->drainRequests);
        self::assertNotNull($this->partners->find($this->partner->id)?->pushLastFailedAt);

        // Not due yet: nothing happens before the backoff elapses.
        self::assertSame(0, $this->worker()->drain(self::NOW + 30)['failed']);
        self::assertCount(1, $this->client->requests);

        $second = $this->worker()->drain(self::NOW + 60);
        self::assertSame(1, $second['failed']);
        $row = $this->queue->all()[0];
        self::assertSame(2, $row->attempts);
        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW + 60 + 120), $row->nextAttemptAt);
        self::assertStringContainsString('connection refused', (string) $row->lastError);
    }

    public function testTheBackoffCapsAtFourHours(): void
    {
        $this->enqueue();
        $this->queue->recordFailure(1, 12, gmdate('Y-m-d H:i:s', self::NOW), 'earlier');
        $this->client->queueJson([], 500);

        $this->worker()->drain(self::NOW);

        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW + 4 * 3600), $this->queue->all()[0]->nextAttemptAt);
    }

    public function testADeliveryIsAbandonedOnceTheTwentyFourHourWindowCloses(): void
    {
        $this->enqueue(self::NOW - 24 * 3600);
        $this->client->queueJson([], 503);

        $counts = $this->worker()->drain(self::NOW);

        self::assertSame(1, $counts['abandoned']);
        self::assertSame([], $this->queue->all());
        $abandoned = array_values(array_filter($this->logger->entries, static fn (array $e): bool => $e['outcome'] === 'push_delivery_abandoned'));
        self::assertCount(1, $abandoned);
        self::assertSame($this->listing->uuid, $abandoned[0]['context']['listing_uuid']);
    }

    public function testAChangeThatLandsWhileADeliveryIsInFlightIsSentAgain(): void
    {
        $this->enqueue();
        $queue = $this->queue;
        $partnerId = $this->partner->id;
        $listingId = $this->listing->id;

        // A client whose callback is answered while an edit folds a newer
        // change into the row being delivered.
        $client = new class ($queue, $partnerId, $listingId) implements FederationClient {
            public int $posts = 0;

            public function __construct(private InMemoryPushDeliveryRepository $queue, private int $partnerId, private int $listingId)
            {
            }

            public function get(Partner $partner, string $pathWithQuery): HttpResponse
            {
                return new HttpResponse(500, '');
            }

            public function post(Partner $partner, string $pathWithQuery, array $payload): HttpResponse
            {
                return new HttpResponse(500, '');
            }

            public function delete(Partner $partner, string $pathWithQuery): HttpResponse
            {
                return new HttpResponse(500, '');
            }

            public function postCallback(string $url, array $payload): HttpResponse
            {
                if ($this->posts++ === 0) {
                    $this->queue->enqueue($this->partnerId, $this->listingId, gmdate('Y-m-d H:i:s', PushDeliveryWorkerTest::NOW + 2));
                }

                return new HttpResponse(200, '{}');
            }
        };

        $counts = $this->worker($client)->drain(self::NOW);

        self::assertSame(1, $counts['delivered']);
        self::assertCount(1, $this->queue->all(), 'the row survives: a newer change is owed');
        self::assertSame(gmdate('Y-m-d H:i:s', self::NOW), $this->queue->all()[0]->nextAttemptAt, 'and goes again right away');
        self::assertSame([self::NOW + 5], $this->drainRequests);

        $this->worker($client)->drain(self::NOW + 5);
        self::assertSame(2, $client->posts);
        self::assertSame([], $this->queue->all());
    }

    #[Group('FP-13')]
    public function testADeliveryToAPartnerNoLongerEntitledIsDroppedUnsent(): void
    {
        $this->enqueue();
        $this->partners->update($this->partner->id, ['trust_level' => TrustLevel::Blocked]);

        $counts = $this->worker()->drain(self::NOW);

        self::assertSame(1, $counts['dropped']);
        self::assertSame([], $this->client->requests);
        self::assertSame([], $this->queue->all());
    }

    public function testADeliveryWithNothingToSayIsDropped(): void
    {
        // The listing became a draft again? It cannot; but a partner the
        // listing was never visible to can be queued by a listing change,
        // and its per-pair decision is "nothing" — the same as its feed.
        $this->listings->update($this->listing->id, ['audience' => 'none']);
        $this->enqueue();

        $counts = $this->worker()->drain(self::NOW);

        self::assertSame(1, $counts['dropped']);
        self::assertSame([], $this->client->requests);
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use OpenYacht\Federation\Audience;
use OpenYacht\Federation\BlockedOutboundHost;
use OpenYacht\Federation\Listing;
use OpenYacht\Federation\ListingSerializer;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\SharingScope;
use OpenYacht\Federation\SharingService;
use OpenYacht\Federation\SubscriptionService;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Http\ListingsEndpoint;
use OpenYacht\Tests\Support\CollectingLogger;
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
 * The authority half of push subscriptions: registration behind the
 * outbound guard, fan-out from the two feed sources into the delivery
 * queue, and the single-pair payload decision that must equal the feed's.
 */
#[Group('API-10')]
final class SubscriptionServiceTest extends TestCase
{
    private InMemoryListingRepository $listings;

    private InMemoryPartnerRepository $partners;

    private InMemoryVisibilityEventRepository $events;

    private InMemoryPushDeliveryRepository $queue;

    private SharingService $sharing;

    private ListingsEndpoint $endpoint;

    private CollectingLogger $logger;

    /** @var list<int> */
    private array $drainRequests = [];

    private Partner $subscribed;

    private Partner $polling;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('home_url')->justReturn('https://node.test/');
        Functions\when('get_bloginfo')->justReturn('Test Node');
        Functions\when('get_option')->justReturn([]);

        $audience = new InMemoryAudienceRepository();
        $this->events = new InMemoryVisibilityEventRepository();
        $groups = new InMemoryPartnerGroupRepository();
        $this->listings = new InMemoryListingRepository($audience, $this->events, $groups);
        $this->partners = new InMemoryPartnerRepository();
        $this->queue = new InMemoryPushDeliveryRepository();
        $this->logger = new CollectingLogger();
        $this->sharing = new SharingService($this->listings, $this->partners, $audience, $groups, $this->events, $this->logger);
        $serializer = new ListingSerializer(new InMemoryPriceHistoryRepository(), new InMemoryListingMediaRepository());
        $this->endpoint = new ListingsEndpoint($this->listings, $serializer, $this->sharing);

        $this->subscribed = $this->partners->insert([
            'domain' => 'subscribed.example',
            'trust_level' => TrustLevel::Verified,
            'push_callback_url' => 'https://subscribed.example/openyacht/v1/inbox',
        ]);
        $this->polling = $this->partners->insert(['domain' => 'polling.example', 'trust_level' => TrustLevel::Verified]);
    }

    private function service(bool $offered = true): SubscriptionService
    {
        return new SubscriptionService(
            $this->partners,
            $this->queue,
            $this->sharing,
            $this->events,
            new ListingSerializer(new InMemoryPriceHistoryRepository(), new InMemoryListingMediaRepository()),
            $this->logger,
            offered: $offered,
            scheduleDrain: function (int $at): void {
                $this->drainRequests[] = $at;
            },
        );
    }

    private function seedListing(string $name = 'VESSEL', ListingStatus $status = ListingStatus::Active, string $updatedAt = '2026-08-01 10:00:00'): Listing
    {
        return $this->listings->insert([
            'uuid' => sprintf('%08x-aaaa-4bbb-8ccc-dddddddddddd', crc32($name)),
            'status' => $status,
            'name' => $name,
            'federation_updated_at' => $updatedAt,
        ]);
    }

    /**
     * @return list<array{partner: int, listing: int}>
     */
    private function queued(): array
    {
        return array_map(static fn ($d): array => ['partner' => $d->partnerId, 'listing' => $d->listingId], $this->queue->all());
    }

    #[Group('FP-14')]
    public function testRegisterStoresACallbackOnlyAfterTheOutboundGuardPasses(): void
    {
        $service = $this->service();

        try {
            $service->register($this->polling, 'http://polling.example/inbox');
            self::fail('a plain-http callback was accepted');
        } catch (BlockedOutboundHost) {
            self::assertNull($this->partners->find($this->polling->id)?->pushCallbackUrl, 'nothing stored for a refused callback');
        }

        $service->register($this->polling, 'https://polling.example/openyacht/v1/inbox');

        $fresh = $this->partners->find($this->polling->id);
        self::assertSame('https://polling.example/openyacht/v1/inbox', $fresh?->pushCallbackUrl);
        self::assertNotNull($fresh?->pushCallbackRegisteredAt);
        self::assertTrue($fresh?->receivesPushes());
        self::assertNotEmpty(array_filter($this->logger->entries, static fn (array $e): bool => $e['outcome'] === 'subscription_registered'));
    }

    public function testUnregisterClearsTheCallbackAndAnythingQueuedForIt(): void
    {
        $service = $this->service();
        $listing = $this->seedListing();
        $service->listingChanged($listing);
        self::assertCount(1, $this->queue->all());

        $service->unregister($this->partners->find($this->subscribed->id));

        self::assertNull($this->partners->find($this->subscribed->id)?->pushCallbackUrl);
        self::assertSame([], $this->queue->all(), 'queued deliveries for the partner are dropped');
    }

    public function testAListingChangeQueuesOneDeliveryPerPartnerEntitledToPushes(): void
    {
        // A callback alone is not enough: a partner blocked or downgraded
        // after subscribing stops receiving at once (FP-13).
        $this->partners->insert([
            'domain' => 'downgraded.example',
            'trust_level' => TrustLevel::Provisional,
            'push_callback_url' => 'https://downgraded.example/inbox',
        ]);
        $listing = $this->seedListing();

        $queued = $this->service()->listingChanged($listing);

        self::assertSame(1, $queued);
        self::assertSame([['partner' => $this->subscribed->id, 'listing' => $listing->id]], $this->queued());
        self::assertCount(1, $this->drainRequests, 'the worker is asked to run');
    }

    #[Group('LS-7')]
    public function testDraftsAndASwitchedOffFeatureQueueNothing(): void
    {
        $draft = $this->seedListing('DRAFT', ListingStatus::Draft);
        self::assertSame(0, $this->service()->listingChanged($draft));

        $active = $this->seedListing('ACTIVE');
        self::assertSame(0, $this->service(offered: false)->listingChanged($active));

        self::assertSame([], $this->queue->all());
        self::assertSame([], $this->drainRequests);
    }

    public function testASecondChangeToAWaitingListingFoldsIntoTheSameRow(): void
    {
        $listing = $this->seedListing();
        $service = $this->service();

        $service->listingChanged($listing);
        $service->listingChanged($listing);

        self::assertCount(1, $this->queue->all(), 'one live delivery per (partner, listing)');
    }

    public function testAVisibilityTransitionQueuesForThatPartnerAlone(): void
    {
        $listing = $this->seedListing();
        $service = $this->service();

        $service->visibilityChanged($listing->id, $this->subscribed->id);
        $service->visibilityChanged($listing->id, $this->polling->id);

        self::assertSame([['partner' => $this->subscribed->id, 'listing' => $listing->id]], $this->queued());
    }

    #[Group('API-3')]
    public function testThePayloadForAPairIsWhatTheFeedWouldServe(): void
    {
        $listing = $this->seedListing();
        $service = $this->service();

        // Visible: the gated listing, byte for byte what the feed serves.
        $cold = $this->endpoint->index($this->subscribed, [])['payload']['data'][0];
        self::assertSame($cold, $service->payloadFor($listing, $this->subscribed));
        self::assertArrayNotHasKey('tombstone', $cold);

        // Unshared: a withdrawn tombstone stamped at the transition, as
        // the feed reports it against a watermark after the edit.
        $this->sharing->setAudience($listing, Audience::Selected, [$this->polling->id]);
        $fresh = $this->listings->find($listing->id);
        $delta = $this->endpoint->index($this->subscribed, ['updated_since' => '2026-08-10T00:00:00Z'])['payload']['data'][0];
        $pushed = $service->payloadFor($fresh, $this->subscribed);
        self::assertTrue($delta['tombstone']);
        self::assertSame('withdrawn', $delta['status']);
        self::assertSame($delta, $pushed);
        self::assertSame($this->events->events[0]['occurred_at'], gmdate('Y-m-d H:i:s', strtotime($pushed['updated_at'])), 'stamped at the transition, not the listing');

        // Re-shared: the listing again, with its own updated_at.
        $this->sharing->setAudience($fresh, Audience::Everyone);
        $again = $service->payloadFor($this->listings->find($listing->id), $this->subscribed);
        self::assertArrayNotHasKey('tombstone', $again);
        self::assertSame($cold['updated_at'], $again['updated_at']);
    }

    public function testNothingIsDeliveredForAListingThePartnerNeverSaw(): void
    {
        // A curated partner without a pivot never saw an everyone-audience
        // listing; neither the feed nor a push tells it anything.
        $curated = $this->partners->insert([
            'domain' => 'curated.example',
            'trust_level' => TrustLevel::Verified,
            'sharing_scope' => SharingScope::Curated,
            'push_callback_url' => 'https://curated.example/inbox',
        ]);
        $listing = $this->seedListing();

        self::assertNull($this->service()->payloadFor($listing, $curated));
        self::assertNull($this->service()->payloadFor($this->seedListing('DRAFT', ListingStatus::Draft), $this->subscribed));
    }

    #[Group('ID-8')]
    public function testAnEndedListingDeliversItsRealStatusTombstone(): void
    {
        $sold = $this->seedListing('SOLD', ListingStatus::Sold);

        $payload = $this->service()->payloadFor($sold, $this->subscribed);

        self::assertTrue($payload['tombstone']);
        self::assertSame('sold', $payload['status']);
    }
}

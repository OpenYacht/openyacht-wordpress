<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use OpenYacht\Federation\Audience;
use OpenYacht\Federation\ErrorCode;
use OpenYacht\Federation\ListingSerializer;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\SharingService;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Http\ListingsEndpoint;
use OpenYacht\Tests\Support\CollectingLogger;
use OpenYacht\Tests\Support\InMemoryAudienceRepository;
use OpenYacht\Tests\Support\InMemoryListingMediaRepository;
use OpenYacht\Tests\Support\InMemoryListingRepository;
use OpenYacht\Tests\Support\InMemoryPartnerGroupRepository;
use OpenYacht\Tests\Support\InMemoryPartnerRepository;
use OpenYacht\Tests\Support\InMemoryPriceHistoryRepository;
use OpenYacht\Tests\Support\InMemoryVisibilityEventRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The granular-sharing feed semantics (the design-lead piece the
 * reference app back-ports): per-partner tombstones on unshare, re-share
 * resurfacing against an arbitrary watermark, and the no-leak posture.
 */
#[Group('API-3')]
#[Group('API-5')]
final class SharingFeedTest extends TestCase
{
    private InMemoryListingRepository $listings;

    private InMemoryPartnerRepository $partners;

    private InMemoryVisibilityEventRepository $events;

    private InMemoryPartnerGroupRepository $groups;

    private SharingService $sharing;

    private ListingsEndpoint $endpoint;

    private Partner $partnerA;

    private Partner $partnerB;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('home_url')->justReturn('https://node.test/');
        Functions\when('get_bloginfo')->justReturn('Test Node');
        Functions\when('get_option')->justReturn([]);

        $audience = new InMemoryAudienceRepository();
        $this->events = new InMemoryVisibilityEventRepository();
        $this->groups = new InMemoryPartnerGroupRepository();
        $this->listings = new InMemoryListingRepository($audience, $this->events, $this->groups);
        $this->partners = new InMemoryPartnerRepository();
        $this->sharing = new SharingService($this->listings, $this->partners, $audience, $this->groups, $this->events, new CollectingLogger());
        $this->endpoint = new ListingsEndpoint(
            $this->listings,
            new ListingSerializer(new InMemoryPriceHistoryRepository(), new InMemoryListingMediaRepository()),
            $this->sharing,
        );

        $this->partnerA = $this->partners->insert(['domain' => 'a.example', 'trust_level' => TrustLevel::Verified]);
        $this->partnerB = $this->partners->insert(['domain' => 'b.example', 'trust_level' => TrustLevel::Verified]);
    }

    private function seedListing(string $name = 'SHARED VESSEL', string $updatedAt = '2026-08-01 10:00:00'): \OpenYacht\Federation\Listing
    {
        return $this->listings->insert([
            'uuid' => sprintf('%08x-aaaa-4bbb-8ccc-dddddddddddd', crc32($name)),
            'status' => ListingStatus::Active,
            'name' => $name,
            'federation_updated_at' => $updatedAt,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function feed(Partner $partner, ?string $since = null): array
    {
        $query = $since !== null ? ['updated_since' => $since] : [];

        return $this->endpoint->index($partner, $query)['payload']['data'];
    }

    public function testUnshareSurfacesAsAWithdrawnTombstoneOnlyForThatPartner(): void
    {
        $listing = $this->seedListing();
        $watermark = '2026-08-10T00:00:00Z';

        // Later, the listing is unshared from partner A only.
        $this->sharing->setAudience($listing, Audience::Selected, [$this->partnerB->id]);

        $feedA = $this->feed($this->partnerA, $watermark);
        self::assertCount(1, $feedA);
        self::assertTrue($feedA[0]['tombstone'], 'a poller must never miss a removal');
        self::assertSame('withdrawn', $feedA[0]['status'], 'indistinguishable from a real withdrawal');

        $feedB = $this->feed($this->partnerB, $watermark);
        self::assertCount(0, $feedB, 'partner B saw no change at all');

        self::assertCount(1, $this->feed($this->partnerB), 'cold sync still serves it to B');
    }

    public function testReShareResurfacesTheListingAgainstTheOriginalWatermark(): void
    {
        $listing = $this->seedListing('RESHARE TEST', '2026-08-01 10:00:00');
        $watermark = '2026-08-10T00:00:00Z'; // after the last content change

        // Sequence after the watermark: unshare from A, then re-share.
        $this->events->append($listing->id, $this->partnerA->id, 'hidden', '2026-08-12 09:00:00');
        $this->events->append($listing->id, $this->partnerA->id, 'visible', '2026-08-14 09:00:00');

        $feed = $this->feed($this->partnerA, $watermark);

        self::assertCount(1, $feed);
        self::assertArrayNotHasKey('tombstone', $feed[0], 'the re-shared listing surfaces as a normal listing again');
        self::assertSame('RESHARE TEST', $feed[0]['listing']['name']);

        // Against a watermark BETWEEN the two transitions, the tombstone shows.
        $between = $this->feed($this->partnerA, '2026-08-11T00:00:00Z');
        self::assertCount(1, $between);
        self::assertArrayNotHasKey('tombstone', $between[0], 'latest state wins: visible again');
    }

    public function testUnshareThenPollThenReShareDeliversTombstoneThenListing(): void
    {
        $listing = $this->seedListing('SEQUENCE TEST', '2026-08-01 10:00:00');

        // Poll 1 (cold): listing present.
        self::assertCount(1, $this->feed($this->partnerA));

        // Unshare; poll 2 with watermark before the transition: tombstone.
        $this->sharing->setAudience($listing, Audience::None);
        $poll2 = $this->feed($this->partnerA, '2026-08-05T00:00:00Z');
        self::assertTrue($poll2[0]['tombstone']);
        $afterUnshare = '2026-08-25T00:00:00Z'; // consumer's new watermark (after the transition)

        // Re-share; poll 3 from the post-unshare watermark: the listing is back.
        $this->events->append($listing->id, $this->partnerA->id, 'visible', '2026-08-26 09:00:00');
        $this->listings->update($listing->id, ['audience' => Audience::Everyone]);
        $poll3 = $this->feed($this->partnerA, $afterUnshare);
        self::assertCount(1, $poll3);
        self::assertArrayNotHasKey('tombstone', $poll3[0]);
    }

    public function testColdSyncExcludesHiddenListingsEntirely(): void
    {
        $listing = $this->seedListing('HIDDEN ONE');
        $this->seedListing('VISIBLE ONE');
        $this->sharing->setAudience($listing, Audience::None);

        $names = array_column(array_column($this->feed($this->partnerA), 'listing'), 'name');

        self::assertSame(['VISIBLE ONE'], $names);
    }

    public function testSelectedAudienceNarrowsToTheSelectedPartner(): void
    {
        $listing = $this->seedListing('SELECTIVE');
        $this->sharing->setAudience($listing, Audience::Selected, [$this->partnerB->id]);

        self::assertCount(0, $this->feed($this->partnerA));
        self::assertCount(1, $this->feed($this->partnerB));
        self::assertFalse($this->sharing->isVisibleTo($this->listings->find($listing->id), $this->partnerA));
        self::assertTrue($this->sharing->isVisibleTo($this->listings->find($listing->id), $this->partnerB));
    }

    public function testHiddenListingDereferencesAsNotFound(): void
    {
        $listing = $this->seedListing('SECRET');
        $this->sharing->setAudience($listing, Audience::Selected, [$this->partnerB->id]);

        $forA = $this->endpoint->show($this->partnerA, $listing->uuid);
        $forB = $this->endpoint->show($this->partnerB, $listing->uuid);

        self::assertSame(ErrorCode::NotFound, $forA['error'], 'same response as a listing that does not exist');
        self::assertSame(200, $forB['status']);
    }

    #[Group('API-4')]
    public function testGrantsChangeResendsTheWholeFeedToThatPartnerOnly(): void
    {
        $this->seedListing('ALPHA', '2026-08-01 10:00:00');
        $this->seedListing('BETA', '2026-08-02 10:00:00');
        $watermark = '2026-08-10T00:00:00Z'; // both listings are older than this

        self::assertCount(0, $this->feed($this->partnerA, $watermark), 'nothing changed yet');

        $refreshed = $this->sharing->refreshPartnerFeed($this->partnerA->id, 'grants changed');

        self::assertSame(2, $refreshed);
        $feedA = $this->feed($this->partnerA, $watermark);
        self::assertCount(2, $feedA, 'the re-gated payloads resend to the affected partner');
        self::assertArrayNotHasKey('tombstone', $feedA[0]);
        self::assertCount(0, $this->feed($this->partnerB, $watermark), 'other partners see no churn');
    }

    public function testAudienceChangeWithoutEffectRecordsNoEvents(): void
    {
        $listing = $this->seedListing('NO-OP');

        $result = $this->sharing->setAudience($listing, Audience::Selected, [$this->partnerA->id, $this->partnerB->id]);

        self::assertSame(['hidden' => 0, 'revealed' => 0], $result, 'everyone -> selected-with-all changes nothing');
        self::assertCount(0, $this->events->events);
    }

    public function testSelectingAGroupSharesWithItsMembersOnly(): void
    {
        $offices = $this->groups->create('Offices');
        $this->groups->replaceMembers($offices->id, [$this->partnerA->id]);

        $listing = $this->seedListing('GROUP SHARE');
        $this->sharing->setAudience($listing, Audience::Selected, [], [$offices->id]);

        self::assertCount(1, $this->feed($this->partnerA), 'group member receives the listing');
        self::assertCount(0, $this->feed($this->partnerB), 'non-member does not');
        self::assertTrue($this->sharing->isVisibleTo($this->listings->find($listing->id), $this->partnerA));
    }

    public function testAddingAPartnerToASelectedGroupSurfacesEveryListingSelectingIt(): void
    {
        $offices = $this->groups->create('Offices');
        $this->groups->replaceMembers($offices->id, [$this->partnerA->id]);

        $listing = $this->seedListing('GROUP JOIN', '2026-08-01 10:00:00');
        $this->sharing->setAudience($listing, Audience::Selected, [], [$offices->id]);
        $watermark = '2026-08-10T00:00:00Z'; // partner B's watermark, after all content changes

        $result = $this->sharing->replaceGroupMembers($offices->id, [$this->partnerA->id, $this->partnerB->id]);

        self::assertSame(['hidden' => 0, 'revealed' => 1], $result);
        $feedB = $this->feed($this->partnerB, $watermark);
        self::assertCount(1, $feedB, 'the joined partner picks the listing up against its old watermark');
        self::assertArrayNotHasKey('tombstone', $feedB[0]);
    }

    public function testRemovingAPartnerFromAGroupTombstonesUnlessStillVisibleAnotherWay(): void
    {
        $offices = $this->groups->create('Offices');
        $this->groups->replaceMembers($offices->id, [$this->partnerA->id, $this->partnerB->id]);

        $viaGroupOnly = $this->seedListing('VIA GROUP ONLY', '2026-08-01 10:00:00');
        $this->sharing->setAudience($viaGroupOnly, Audience::Selected, [], [$offices->id]);

        $alsoIndividual = $this->seedListing('ALSO INDIVIDUAL', '2026-08-01 10:00:00');
        $this->sharing->setAudience($alsoIndividual, Audience::Selected, [$this->partnerB->id], [$offices->id]);

        $watermark = '2026-08-10T00:00:00Z';
        $result = $this->sharing->replaceGroupMembers($offices->id, [$this->partnerA->id]);

        self::assertSame(['hidden' => 1, 'revealed' => 0], $result, 'only the group-only listing transitions');

        $feedB = $this->feed($this->partnerB, $watermark);
        self::assertCount(1, $feedB);
        self::assertTrue($feedB[0]['tombstone'], 'group-only listing tombstones for the removed partner');

        self::assertCount(1, $this->feed($this->partnerB), 'cold sync still serves the individually shared listing');
        self::assertCount(0, $this->feed($this->partnerA, $watermark), 'the remaining member sees no churn');
    }

    public function testDeletingAGroupTombstonesItsListingsForFormerMembers(): void
    {
        $offices = $this->groups->create('Offices');
        $this->groups->replaceMembers($offices->id, [$this->partnerA->id]);

        $listing = $this->seedListing('GROUP DELETE', '2026-08-01 10:00:00');
        $this->sharing->setAudience($listing, Audience::Selected, [], [$offices->id]);
        $watermark = '2026-08-10T00:00:00Z';

        $this->sharing->deleteGroup($offices->id);

        $feedA = $this->feed($this->partnerA, $watermark);
        self::assertCount(1, $feedA);
        self::assertTrue($feedA[0]['tombstone']);
        self::assertNull($this->groups->find($offices->id));
        self::assertSame([], $this->groups->groupIdsForListing($listing->id), 'the listing selection rows are gone too');
    }
}

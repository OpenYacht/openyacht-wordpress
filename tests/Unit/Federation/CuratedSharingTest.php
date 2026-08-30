<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use OpenYacht\Federation\Audience;
use OpenYacht\Federation\ErrorCode;
use OpenYacht\Federation\Listing;
use OpenYacht\Federation\ListingSerializer;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\SharingScope;
use OpenYacht\Federation\SharingService;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Federation\WpdbListingRepository;
use OpenYacht\Federation\WpdbPartnerRepository;
use OpenYacht\Http\ListingsEndpoint;
use OpenYacht\Schema;
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
 * Curated-partner sharing: the additive-pivot visibility rule mirrored
 * across implementations. A curated partner receives only listings
 * explicitly selected for it (directly or via a group); explicit pivots
 * grant visibility under any audience except none, so curating one
 * partner never changes what anyone else sees.
 */
#[Group('API-3')]
#[Group('API-5')]
final class CuratedSharingTest extends TestCase
{
    private InMemoryListingRepository $listings;

    private InMemoryPartnerRepository $partners;

    private InMemoryAudienceRepository $audience;

    private InMemoryVisibilityEventRepository $events;

    private InMemoryPartnerGroupRepository $groups;

    private SharingService $sharing;

    private ListingsEndpoint $endpoint;

    private Partner $standard;

    private Partner $curated;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('home_url')->justReturn('https://node.test/');
        Functions\when('get_bloginfo')->justReturn('Test Node');
        Functions\when('get_option')->justReturn([]);

        $this->audience = new InMemoryAudienceRepository();
        $this->events = new InMemoryVisibilityEventRepository();
        $this->groups = new InMemoryPartnerGroupRepository();
        $this->listings = new InMemoryListingRepository($this->audience, $this->events, $this->groups);
        $this->partners = new InMemoryPartnerRepository();
        $this->sharing = new SharingService($this->listings, $this->partners, $this->audience, $this->groups, $this->events, new CollectingLogger());
        $this->endpoint = new ListingsEndpoint(
            $this->listings,
            new ListingSerializer(new InMemoryPriceHistoryRepository(), new InMemoryListingMediaRepository()),
            $this->sharing,
        );

        $this->standard = $this->partners->insert(['domain' => 'standard.example', 'trust_level' => TrustLevel::Verified]);
        $this->curated = $this->partners->insert([
            'domain' => 'curated.example',
            'trust_level' => TrustLevel::Verified,
            'sharing_scope' => SharingScope::Curated,
        ]);
    }

    private function seedListing(string $name = 'VESSEL', string $type = 'sale', string $updatedAt = '2026-08-01 10:00:00'): Listing
    {
        return $this->listings->insert([
            'uuid' => sprintf('%08x-aaaa-4bbb-8ccc-dddddddddddd', crc32($name . $type)),
            'type' => $type,
            'status' => ListingStatus::Active,
            'name' => $name,
            'federation_updated_at' => $updatedAt,
        ]);
    }

    /**
     * @return list<int> ids of the listings the partner's cold sync serves
     */
    private function coldFeedIds(Partner $partner): array
    {
        return array_map(
            static fn ($item): int => $item->listing->id,
            $this->listings->feedPage($partner->id, $partner->sharingScope, null, null, 100),
        );
    }

    public function testVisibilityTruthTableAndFeedAgreeAcrossScopePivotAndAudience(): void
    {
        $group = $this->groups->create('Show organisers');

        foreach ([Audience::Everyone, Audience::Selected, Audience::None] as $audience) {
            foreach (['none', 'direct', 'group'] as $pivot) {
                foreach ([$this->standard, $this->curated] as $partner) {
                    $this->groups->replaceMembers($group->id, $pivot === 'group' ? [$partner->id] : []);
                    $listing = $this->seedListing("TT {$audience->value} {$pivot} {$partner->domain}");
                    $partnerIds = $pivot === 'direct' ? [$partner->id] : [];
                    $groupIds = $pivot === 'group' ? [$group->id] : [];

                    // Pivots survive a move to 'none', so seed them first.
                    $this->sharing->setAudience($listing, Audience::Selected, $partnerIds, $groupIds);

                    if ($audience !== Audience::Selected) {
                        $this->sharing->setAudience($listing, $audience, $partnerIds, $groupIds);
                    }

                    $expected = $audience !== Audience::None
                        && ($pivot !== 'none'
                            || ($audience === Audience::Everyone && $partner->sharingScope === SharingScope::Standard));

                    $case = "audience={$audience->value} pivot={$pivot} scope={$partner->sharingScope->value}";
                    $listing = $this->listings->find($listing->id);
                    self::assertSame($expected, $this->sharing->isVisibleTo($listing, $partner), "isVisibleTo: {$case}");
                    self::assertSame(
                        $expected,
                        in_array($listing->id, $this->coldFeedIds($partner), true),
                        "feedPage must agree with isVisibleTo: {$case}",
                    );
                }
            }
        }
    }

    public function testFeedSqlImplementsTheAdditiveRule(): void
    {
        $wpdb = new \wpdb();
        (new WpdbListingRepository($wpdb))->feedPage(7, SharingScope::Curated, null, null, 10);
        $sql = (string) end($wpdb->queries);

        // The pivot EXISTS checks apply under any audience but 'none'; the
        // everyone arm serves standard scope only (here: never, 'curated').
        self::assertStringContainsString("l.audience != 'none'", $sql);
        self::assertStringContainsString('a.partner_id = 7', $sql);
        self::assertStringContainsString('gm.partner_id = 7', $sql);
        self::assertStringContainsString("l.audience = 'everyone' AND 'curated' = 'standard'", $sql);
    }

    public function testMovingSelectedToEveryoneKeepsThePivotRows(): void
    {
        $listing = $this->seedListing('KEEP PIVOTS');
        $this->sharing->setAudience($listing, Audience::Selected, [$this->curated->id]);

        $result = $this->sharing->setAudience($this->listings->find($listing->id), Audience::Everyone, [$this->curated->id]);

        self::assertSame([$this->curated->id], $this->audience->partnersForListing($listing->id), 'the lineup survives the move to everyone');
        self::assertSame(['hidden' => 0, 'revealed' => 1], $result, 'only the standard partner gains the everyone arm');
        self::assertTrue($this->sharing->isVisibleTo($this->listings->find($listing->id), $this->curated));
    }

    public function testHidingThenReshowingPreservesTheCuratedLineup(): void
    {
        $listing = $this->seedListing('HIDE RESHOW');
        $this->sharing->setAudience($listing, Audience::Everyone, [$this->curated->id]);

        // Temporarily hide: no picker submit, pivots left untouched.
        $hide = $this->sharing->setAudience($this->listings->find($listing->id), Audience::None);
        self::assertSame(['hidden' => 2, 'revealed' => 0], $hide, 'both partners lose the listing');
        self::assertSame([$this->curated->id], $this->audience->partnersForListing($listing->id), 'hiding must not drop the curated lineup');

        // Re-show: the form resubmits the persisted lineup.
        $show = $this->sharing->setAudience($this->listings->find($listing->id), Audience::Everyone, $this->audience->partnersForListing($listing->id));
        self::assertSame(['hidden' => 0, 'revealed' => 2], $show);
        self::assertTrue($this->sharing->isVisibleTo($this->listings->find($listing->id), $this->curated));
    }

    public function testCuratedPartnerGainsAndLosesOnlyThroughItsPivot(): void
    {
        $listing = $this->seedListing('EVERYONE PLUS ONE');

        self::assertFalse($this->sharing->isVisibleTo($listing, $this->curated), 'the everyone arm does not reach a curated partner');
        self::assertTrue($this->sharing->isVisibleTo($listing, $this->standard));

        $add = $this->sharing->setAudience($listing, Audience::Everyone, [$this->curated->id]);
        self::assertSame(['hidden' => 0, 'revealed' => 1], $add, 'adding the pivot reveals it to the curated partner only');

        $remove = $this->sharing->setAudience($this->listings->find($listing->id), Audience::Everyone, []);
        self::assertSame(['hidden' => 1, 'revealed' => 0], $remove, 'clearing the lineup hides it again — no one else moves');
    }

    public function testScopeFlipReplaysThroughTheVisibilityEventLog(): void
    {
        $everyoneOnly = $this->seedListing('EVERYONE ONLY', 'sale', '2026-08-01 10:00:00');
        $everyonePivoted = $this->seedListing('EVERYONE PIVOTED');
        $selected = $this->seedListing('SELECTED');
        $draft = $this->listings->insert([
            'uuid' => 'dddddddd-aaaa-4bbb-8ccc-dddddddddddd',
            'status' => ListingStatus::Draft,
            'name' => 'DRAFT',
        ]);
        $this->sharing->setAudience($everyonePivoted, Audience::Everyone, [$this->standard->id]);
        $this->sharing->setAudience($selected, Audience::Selected, [$this->standard->id]);
        $this->events->events = [];

        $watermark = '2026-08-10T00:00:00Z';
        $flip = $this->sharing->setSharingScope($this->standard, SharingScope::Curated);

        self::assertSame(['hidden' => 1, 'revealed' => 0], $flip, 'only the everyone-only listing transitions; drafts emit nothing');
        $feed = $this->endpoint->index($this->partners->find($this->standard->id), ['updated_since' => $watermark])['payload']['data'];
        self::assertCount(1, $feed);
        self::assertTrue($feed[0]['tombstone'], 'the everyone-only listing tombstones');
        self::assertSame(
            '2026-08-01 10:00:00',
            $this->listings->find($everyoneOnly->id)->federationUpdatedAt,
            'a sharing change never moves the wire timestamp — the event log carries the transition',
        );

        $back = $this->sharing->setSharingScope($this->partners->find($this->standard->id), SharingScope::Standard);
        self::assertSame(['hidden' => 0, 'revealed' => 1], $back, 'flipping back resurfaces it');

        $noop = $this->sharing->setSharingScope($this->partners->find($this->standard->id), SharingScope::Standard);
        self::assertSame(['hidden' => 0, 'revealed' => 0], $noop, 'setting the current scope is a no-op');
        self::assertNull($this->listings->find($draft->id)->federationUpdatedAt);
    }

    public function testEveryoneAudienceListingDereferencesAsNotFoundForACuratedPartner(): void
    {
        $listing = $this->seedListing('NO LEAK');

        $forCurated = $this->endpoint->show($this->curated, $listing->uuid);
        self::assertSame(ErrorCode::NotFound, $forCurated['error'], 'same response as a listing that does not exist');

        $this->sharing->setAudience($listing, Audience::Everyone, [$this->curated->id]);
        self::assertSame(200, $this->endpoint->show($this->curated, $listing->uuid)['status']);
    }

    public function testSavingOneTypesDirectSharesNeverUnsharesTheOtherType(): void
    {
        $sale = $this->seedListing('TWIN', 'sale');
        $charter = $this->seedListing('TWIN', 'charter');
        $this->sharing->replaceDirectShares($this->curated, 'sale', [$sale->id]);
        $this->sharing->replaceDirectShares($this->curated, 'charter', [$charter->id]);

        // The trap: saving the sale picker with the sale share removed must
        // leave the charter share alone — the form never showed it.
        $result = $this->sharing->replaceDirectShares($this->curated, 'sale', []);

        self::assertSame(['hidden' => 1, 'revealed' => 0], $result);
        self::assertSame([$charter->id], $this->audience->listingIdsForPartner($this->curated->id), 'the charter share survives a sale-picker save');
        self::assertTrue($this->sharing->isVisibleTo($this->listings->find($charter->id), $this->curated));
        self::assertFalse($this->sharing->isVisibleTo($this->listings->find($sale->id), $this->curated));
    }

    public function testUntickingADirectShareLeavesTheGroupDerivedShareIntact(): void
    {
        $group = $this->groups->create('Offices');
        $this->groups->replaceMembers($group->id, [$this->curated->id]);
        $listing = $this->seedListing('VIA BOTH');
        $this->sharing->setAudience($listing, Audience::Everyone, [$this->curated->id], [$group->id]);
        $this->events->events = [];

        $result = $this->sharing->replaceDirectShares($this->curated, 'sale', []);

        self::assertSame(['hidden' => 0, 'revealed' => 0], $result, 'still reachable via the group: no transition');
        self::assertSame([], $this->audience->partnersForListing($listing->id), 'the direct share is gone');
        self::assertSame([$this->curated->id], $this->groups->members($group->id), 'group membership is never touched');
        self::assertTrue($this->sharing->isVisibleTo($this->listings->find($listing->id), $this->curated));
    }

    public function testDirectShareChangesOnDraftsWritePivotsButEmitNothing(): void
    {
        $draft = $this->listings->insert([
            'uuid' => 'eeeeeeee-aaaa-4bbb-8ccc-dddddddddddd',
            'type' => 'sale',
            'status' => ListingStatus::Draft,
            'name' => 'DRAFT SHARE',
        ]);

        $result = $this->sharing->replaceDirectShares($this->curated, 'sale', [$draft->id]);

        self::assertSame(['hidden' => 0, 'revealed' => 0], $result);
        self::assertSame([$this->curated->id], $this->audience->partnersForListing($draft->id), 'the selection is stored for publication');
        self::assertCount(0, $this->events->events, 'drafts were never distributed — nothing to transition');
    }

    public function testPartnersSchemaCarriesTheScopeColumnWithAStandardDefault(): void
    {
        $tables = (new Schema(new \wpdb()))->tables();

        self::assertStringContainsString("sharing_scope varchar(16) NOT NULL DEFAULT 'standard'", $tables['partners']);
        self::assertGreaterThanOrEqual(8, Schema::VERSION, 'the column addition rides a schema version bump');
    }

    public function testPartnerRowWithoutTheColumnHydratesAsStandard(): void
    {
        $wpdb = new \wpdb();
        $wpdb->row = ['id' => 3, 'domain' => 'old.example'];

        self::assertSame(SharingScope::Standard, (new WpdbPartnerRepository($wpdb))->find(3)->sharingScope);

        $wpdb->row = ['id' => 4, 'domain' => 'new.example', 'sharing_scope' => 'curated'];
        self::assertSame(SharingScope::Curated, (new WpdbPartnerRepository($wpdb))->find(4)->sharingScope);
    }
}

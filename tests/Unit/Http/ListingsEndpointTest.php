<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Http;

use Brain\Monkey\Functions;
use OpenYacht\Federation\ErrorCode;
use OpenYacht\Federation\ListingCursor;
use OpenYacht\Federation\ListingSerializer;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Http\ListingsEndpoint;
use OpenYacht\Tests\Support\InMemoryListingMediaRepository;
use OpenYacht\Tests\Support\InMemoryListingRepository;
use OpenYacht\Tests\Support\InMemoryPriceHistoryRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

final class ListingsEndpointTest extends TestCase
{
    private InMemoryListingRepository $listings;

    private ListingsEndpoint $endpoint;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('home_url')->justReturn('https://node.test/');
        Functions\when('get_bloginfo')->justReturn('Test Node');
        Functions\when('get_option')->justReturn([]);
        $audience = new \OpenYacht\Tests\Support\InMemoryAudienceRepository();
        $events = new \OpenYacht\Tests\Support\InMemoryVisibilityEventRepository();
        $this->listings = new InMemoryListingRepository($audience, $events);
        $partners = new \OpenYacht\Tests\Support\InMemoryPartnerRepository();
        $sharing = new \OpenYacht\Federation\SharingService(
            $this->listings,
            $partners,
            $audience,
            $events,
            new \OpenYacht\Tests\Support\CollectingLogger(),
        );
        $this->endpoint = new ListingsEndpoint(
            $this->listings,
            new ListingSerializer(new InMemoryPriceHistoryRepository(), new InMemoryListingMediaRepository()),
            $sharing,
        );
        $this->partner = $partners->insert(['domain' => 'partner.example', 'trust_level' => TrustLevel::Verified]);
    }

    private function seed(string $name, ListingStatus $status, string $updatedAt): void
    {
        $this->listings->insert([
            'uuid' => sprintf('%08x-aaaa-4bbb-8ccc-dddddddddddd', crc32($name)),
            'status' => $status,
            'name' => $name,
            'federation_updated_at' => $updatedAt,
        ]);
    }

    #[Group('API-2')]
    #[Group('LS-7')]
    public function testColdSyncServesVisibleInventoryOnlyWithCursorPagination(): void
    {
        $this->seed('A', ListingStatus::Active, '2026-08-01 10:00:00');
        $this->seed('B', ListingStatus::Active, '2026-08-02 10:00:00');
        $this->seed('C', ListingStatus::UnderOffer, '2026-08-03 10:00:00');
        $this->seed('DRAFT', ListingStatus::Draft, '2026-08-04 10:00:00');
        $this->seed('SOLD', ListingStatus::Sold, '2026-08-05 10:00:00');

        $first = $this->endpoint->index($this->partner, ['page_size' => 2]);

        self::assertCount(2, $first['payload']['data']);
        self::assertArrayHasKey('next_cursor', $first['payload']['meta']);
        self::assertSame('1.0', $first['payload']['meta']['protocol_version']);

        $second = $this->endpoint->index($this->partner, ['page_size' => 2, 'cursor' => $first['payload']['meta']['next_cursor']]);

        self::assertCount(1, $second['payload']['data'], 'drafts and terminals excluded from cold sync');
        self::assertArrayNotHasKey('next_cursor', $second['payload']['meta'], 'absent, not null, on the last page');

        $names = array_merge(
            array_column(array_column($first['payload']['data'], 'listing'), 'name'),
            array_column(array_column($second['payload']['data'], 'listing'), 'name'),
        );
        self::assertSame(['A', 'B', 'C'], $names);
    }

    #[Group('API-3')]
    public function testUpdatedSinceIncludesTombstonesForTerminalListings(): void
    {
        $this->seed('A', ListingStatus::Active, '2026-08-02 10:00:00');
        $this->seed('SOLD', ListingStatus::Sold, '2026-08-03 10:00:00');
        $this->seed('OLD', ListingStatus::Active, '2026-07-01 10:00:00');

        $result = $this->endpoint->index($this->partner, ['updated_since' => '2026-08-01T00:00:00Z']);
        $data = $result['payload']['data'];

        self::assertCount(2, $data, 'unchanged listings are not resent');
        self::assertArrayNotHasKey('tombstone', $data[0]);
        self::assertTrue($data[1]['tombstone'], 'a poller must never miss a removal');
        self::assertSame('sold', $data[1]['status']);
    }

    public function testInvalidUpdatedSinceIsAValidationError(): void
    {
        $result = $this->endpoint->index($this->partner, ['updated_since' => 'not-a-date']);

        self::assertSame(ErrorCode::ValidationError, $result['error']);
    }

    #[Group('LS-7')]
    public function testShowNeverRevealsDrafts(): void
    {
        $this->seed('DRAFT', ListingStatus::Draft, '2026-08-01 10:00:00');
        $uuid = $this->listings->find(1)->uuid;

        $result = $this->endpoint->show($this->partner, $uuid);

        self::assertSame(ErrorCode::NotFound, $result['error']);
    }

    #[Group('ID-1')]
    public function testShowDereferencesTerminalListingsWithinRetention(): void
    {
        $this->seed('SOLD', ListingStatus::Sold, gmdate('Y-m-d H:i:s', time() - 30 * 86400));
        $uuid = $this->listings->find(1)->uuid;

        $result = $this->endpoint->show($this->partner, $uuid);

        self::assertSame(200, $result['status']);
        self::assertSame('sold', $result['payload']['status']);
    }

    public function testShowReturns410GoneAfterTheRetentionWindow(): void
    {
        $this->seed('ANCIENT', ListingStatus::Sold, '2020-01-01 00:00:00');
        $uuid = $this->listings->find(1)->uuid;

        $result = $this->endpoint->show($this->partner, $uuid);

        self::assertSame(ErrorCode::Gone, $result['error']);
    }

    public function testCursorRoundTripsAndRejectsGarbage(): void
    {
        $cursor = ListingCursor::encode(['updated_at' => '2026-08-23T10:00:00Z', 'id' => 42]);

        self::assertSame(['updated_at' => '2026-08-23 10:00:00', 'id' => 42], ListingCursor::decode($cursor));
        self::assertNull(ListingCursor::decode('not-base64!!'));
        self::assertNull(ListingCursor::decode(base64_encode('{"weird": true}')));
        self::assertNull(ListingCursor::decode(null));
    }
}

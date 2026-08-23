<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use InvalidArgumentException;
use OpenYacht\Federation\ListingService;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Tests\Support\InMemoryListingRepository;
use OpenYacht\Tests\Support\InMemoryPriceHistoryRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

final class ListingLifecycleTest extends TestCase
{
    private InMemoryListingRepository $listings;

    private InMemoryPriceHistoryRepository $prices;

    private ListingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\when('wp_generate_uuid4')->alias(static fn (): string => sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0, 0xFFF), random_int(0, 0xFFFFFFFFFFFF)));
        $this->listings = new InMemoryListingRepository();
        $this->prices = new InMemoryPriceHistoryRepository();
        $this->service = new ListingService($this->listings, $this->prices);
    }

    #[Group('ID-1')]
    public function testCanonicalUuidIsMintedOnceAndImmutable(): void
    {
        $listing = $this->service->create(['name' => 'TEST']);

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $listing->uuid);

        $this->expectException(InvalidArgumentException::class);

        $this->service->update($listing, ['uuid' => 'different-uuid']);
    }

    #[Group('ID-8')]
    public function testLifecycleTransitions(): void
    {
        $listing = $this->service->create(['name' => 'TEST']);
        self::assertSame(ListingStatus::Draft, $listing->status);

        $active = $this->service->transition($listing, ListingStatus::Active);
        self::assertSame(ListingStatus::Active, $active->status);
        self::assertNotNull($active->listedAt, 'first activation stamps listed_at');

        $underOffer = $this->service->transition($active, ListingStatus::UnderOffer);
        $backActive = $this->service->transition($underOffer, ListingStatus::Active);
        $sold = $this->service->transition($backActive, ListingStatus::Sold);
        self::assertSame(ListingStatus::Sold, $sold->status);
        self::assertSame([], $sold->allowedTransitions(), 'terminal states are terminal');

        $this->expectException(InvalidArgumentException::class);

        $this->service->transition($sold, ListingStatus::Active);
    }

    #[Group('ID-8')]
    public function testDraftCannotJumpStraightToSold(): void
    {
        $listing = $this->service->create(['name' => 'TEST']);

        $this->expectException(InvalidArgumentException::class);

        $this->service->transition($listing, ListingStatus::Sold);
    }

    #[Group('LS-10')]
    public function testPriceChangesAppendToHistoryNeverRewrite(): void
    {
        $listing = $this->service->create(['name' => 'TEST', 'price_amount' => '1000000', 'price_currency' => 'EUR']);
        self::assertCount(1, $this->prices->entries, 'creation with a price seeds history');

        $updated = $this->service->update($listing, ['price_amount' => '900000']);
        self::assertCount(2, $this->prices->entries);

        $this->service->update($updated, ['name' => 'RENAMED']);
        self::assertCount(2, $this->prices->entries, 'non-price changes never touch history');

        $history = $this->prices->forListing($listing->id);
        self::assertSame('900000', $history[0]['amount'], 'most recent first; first entry equals current price');
    }

    #[Group('API-4')]
    public function testEveryChangeStampsFederationUpdatedAt(): void
    {
        $listing = $this->service->create(['name' => 'TEST']);
        self::assertNotNull($listing->federationUpdatedAt);

        $this->listings->update($listing->id, ['federation_updated_at' => '2020-01-01 00:00:00']);
        $updated = $this->service->update($this->listings->find($listing->id), ['name' => 'RENAMED']);

        self::assertNotSame('2020-01-01 00:00:00', $updated->federationUpdatedAt);
    }
}

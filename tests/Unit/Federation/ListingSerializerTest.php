<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use OpenYacht\Federation\BuilderRegistry;
use OpenYacht\Federation\CategoryVocabulary;
use OpenYacht\Federation\Listing;
use OpenYacht\Federation\ListingSerializer;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Federation\ListingValidator;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Tests\Support\InMemoryListingMediaRepository;
use OpenYacht\Tests\Support\InMemoryPriceHistoryRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

final class ListingSerializerTest extends TestCase
{
    private InMemoryPriceHistoryRepository $prices;

    private InMemoryListingMediaRepository $media;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('home_url')->justReturn('https://node.test/');
        Functions\when('get_bloginfo')->justReturn('Test Node');
        Functions\when('get_option')->justReturn([]);
        $this->prices = new InMemoryPriceHistoryRepository();
        $this->media = new InMemoryListingMediaRepository();
    }

    private function serializer(): ListingSerializer
    {
        return new ListingSerializer($this->prices, $this->media);
    }

    private function listing(ListingStatus $status = ListingStatus::Active): Listing
    {
        return new Listing(
            id: 1,
            uuid: 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            type: 'sale',
            status: $status,
            name: 'FICTIONAL DAWN',
            summary: 'A fictional test vessel.',
            condition: 'used',
            hin: 'HIN123456789',
            imo: '1234567',
            mmsi: '123456789',
            officialNumber: 'OFF-1',
            builderName: 'Feadship',
            builderSlug: 'feadship',
            modelName: 'Imaginary 42',
            yearBuilt: 2019,
            refitYear: null,
            loaM: 42.5,
            previousNames: ['EX FICTIONAL'],
            priceAmount: '12500000',
            priceCurrency: 'EUR',
            priceOnApplication: false,
            startingPrice: false,
            locationDisplay: 'Testville, Fictionia',
            locationCity: 'Testville',
            locationState: null,
            locationCountry: 'FR',
            locationMarina: 'Marina Imaginaria',
            locationLat: 43.27,
            locationLon: 6.64,
            specifications: ['power_or_sail' => 'power', 'beam_m' => 8.2, 'category' => ['name' => 'Motor Yacht', 'slug' => 'motor-yacht']],
            descriptions: [['section' => 'overview', 'content' => '<p>Fictional.</p>']],
            features: [['category' => 'entertainment', 'name' => 'Imaginary cinema', 'slug' => null]],
            compliance: ['vat_status' => 'paid'],
            listedAt: '2026-08-01 09:00:00',
            federationUpdatedAt: '2026-08-23 10:00:00',
        );
    }

    /**
     * @param list<string>|null $fieldGroups
     */
    private function partner(?array $fieldGroups): Partner
    {
        return new Partner(1, 'partner.example', null, null, null, null, TrustLevel::Verified, $fieldGroups, null, null, 0, null, null);
    }

    #[Group('LS-1')]
    public function testEveryPayloadContainsTheCompleteSchema(): void
    {
        $wire = $this->serializer()->serialize($this->listing(), null);

        self::assertSame(
            ['id', 'type', 'status', 'updated_at', 'listed_at', 'condition', 'agreement', 'vessel', 'listing', 'specifications', 'descriptions', 'features', 'media', 'charter', 'usage', 'compliance'],
            array_keys($wire),
        );
        self::assertCount(39, $wire['specifications'], 'every specification key present, null when unknown');
        self::assertNull($wire['specifications']['gross_tonnage']);
        self::assertSame('https://node.test/openyacht/v1/listings/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $wire['id']);
        self::assertSame('2026-08-23T10:00:00Z', $wire['updated_at']);
    }

    #[Group('LS-1')]
    public function testUngatedWireViewValidatesAgainstTheVendoredSchema(): void
    {
        $this->prices->append(1, '12500000', 'EUR');
        $this->media->insert([
            'listing_id' => 1, 'kind' => 'profile',
            'url' => 'https://node.test/wp-content/uploads/hero.jpg',
            'thumbnail_url' => 'https://node.test/wp-content/uploads/hero-thumb.jpg',
            'sha256' => str_repeat('ab', 32), 'width' => 1600, 'height' => 1000, 'sort' => 0,
        ]);

        $root = dirname(__DIR__, 3);
        $validator = new ListingValidator(
            new BuilderRegistry($root . '/resources/registry/builders.json'),
            new CategoryVocabulary($root . '/resources/registry/categories.json'),
            $root . '/resources/schemas/v1/listing.schema.json',
        );

        $errors = $validator->validate($this->serializer()->serialize($this->listing(), null));

        self::assertSame([], $errors);
    }

    #[Group('LS-14')]
    #[Group('API-5')]
    public function testGatingMapNullsWithheldValuesInPlace(): void
    {
        $this->prices->append(1, '12500000', 'EUR');
        $serializer = $this->serializer();

        $full = $serializer->serialize($this->listing(), $this->partner(null));
        self::assertSame('12500000', $full['listing']['price']['amount']);
        self::assertSame('HIN123456789', $full['vessel']['hin']);
        self::assertSame('Marina Imaginaria', $full['listing']['location']['marina']);
        self::assertNotSame([], $full['listing']['price_history']);

        $gated = $serializer->serialize($this->listing(), $this->partner([]));
        self::assertNull($gated['listing']['price']['amount'], 'pricing withheld: nulled in place');
        self::assertNull($gated['listing']['price']['currency']);
        self::assertNull($gated['vessel']['hin'], 'vessel identifiers withheld');
        self::assertNull($gated['vessel']['mmsi']);
        self::assertNull($gated['listing']['location']['marina'], 'exact location withheld');
        self::assertNull($gated['listing']['location']['coordinates']);
        self::assertSame([], $gated['listing']['price_history'], 'history withheld: emptied');
        self::assertSame('Testville', $gated['listing']['location']['city'], 'display location always shared');
        self::assertSame('Feadship', $gated['vessel']['builder']['name'], 'builder never gated');
    }

    #[Group('API-12')]
    public function testPriceOnApplicationNullsTheAmountEvenWhenPricingIsGranted(): void
    {
        $listing = $this->listing();
        $poa = new Listing(...array_replace(
            $this->listingConstructorArgs($listing),
            ['priceOnApplication' => true],
        ));

        $wire = $this->serializer()->serialize($poa, $this->partner(null));

        self::assertNull($wire['listing']['price']['amount']);
        self::assertTrue($wire['listing']['price']['on_application']);
    }

    #[Group('LS-8')]
    public function testNoImageryMeansProfileNullNeverAPlaceholder(): void
    {
        $wire = $this->serializer()->serialize($this->listing(), null);

        self::assertNull($wire['media']['profile']);
        self::assertSame([], $wire['media']['gallery']);
    }

    #[Group('API-3')]
    public function testTombstoneForm(): void
    {
        $tombstone = $this->serializer()->tombstone($this->listing(ListingStatus::Sold));

        self::assertSame(
            ['id', 'tombstone', 'status', 'updated_at'],
            array_keys($tombstone),
        );
        self::assertTrue($tombstone['tombstone']);
        self::assertSame('sold', $tombstone['status']);
    }

    /**
     * @return array<string, mixed>
     */
    private function listingConstructorArgs(Listing $listing): array
    {
        $args = [];

        foreach ((new \ReflectionClass(Listing::class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $args[$parameter->getName()] = $listing->{$parameter->getName()};
        }

        return $args;
    }
}

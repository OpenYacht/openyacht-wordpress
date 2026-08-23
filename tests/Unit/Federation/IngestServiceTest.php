<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use OpenYacht\Federation\BuilderRegistry;
use OpenYacht\Federation\CategoryVocabulary;
use OpenYacht\Federation\IngestService;
use OpenYacht\Federation\ListingService;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Federation\ListingValidator;
use OpenYacht\Federation\RichTextSanitizer;
use OpenYacht\Tests\Support\InMemoryListingMediaRepository;
use OpenYacht\Tests\Support\InMemoryListingRepository;
use OpenYacht\Tests\Support\InMemoryPriceHistoryRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('LS-1')]
final class IngestServiceTest extends TestCase
{
    private InMemoryListingRepository $listings;

    private InMemoryPriceHistoryRepository $prices;

    private InMemoryListingMediaRepository $media;

    private IngestService $ingest;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('home_url')->justReturn('https://node.test/');
        Functions\when('get_bloginfo')->justReturn('Test Node');
        Functions\when('get_option')->justReturn([]);
        Functions\when('wp_generate_uuid4')->alias(static fn (): string => sprintf('%08x-%04x-4%03x-8%03x-%012x', random_int(0, 0xFFFFFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFF), random_int(0, 0xFFF), random_int(0, 0xFFFFFFFFFFFF)));

        $this->listings = new InMemoryListingRepository();
        $this->prices = new InMemoryPriceHistoryRepository();
        $this->media = new InMemoryListingMediaRepository();
        $root = dirname(__DIR__, 3);
        $this->ingest = new IngestService(
            new ListingService($this->listings, $this->prices),
            $this->media,
            new ListingValidator(
                new BuilderRegistry($root . '/resources/registry/builders.json'),
                new CategoryVocabulary($root . '/resources/registry/categories.json'),
                $root . '/resources/schemas/v1/listing.schema.json',
            ),
            new RichTextSanitizer(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validColumns(): array
    {
        return [
            'name' => 'FICTIONAL DAWN',
            'price_amount' => '1000000',
            'price_currency' => 'EUR',
            'specifications' => ['power_or_sail' => 'power'],
        ];
    }

    public function testValidColumnsCreateAndActivateAListing(): void
    {
        $result = $this->ingest->createFromColumns($this->validColumns(), [[
            'kind' => 'profile',
            'url' => 'https://node.test/uploads/hero.jpg',
            'thumbnail_url' => 'https://node.test/uploads/hero-large.jpg',
            'sha256' => str_repeat('ab', 32),
            'width' => 1600,
            'height' => 1000,
            'sort' => 0,
        ]], ListingStatus::Active);

        self::assertNotInstanceOf(\WP_Error::class, $result);
        self::assertSame(ListingStatus::Active, $result->status);
        self::assertNotNull($result->listedAt);
        self::assertCount(1, $this->media->forListing($result->id));
        self::assertCount(1, $this->prices->entries, 'priced creation seeds history');
    }

    public function testInvalidCandidateIsRefusedWithNothingPersisted(): void
    {
        $columns = $this->validColumns();
        $columns['specifications'] = []; // power_or_sail is the one non-nullable specification

        $result = $this->ingest->createFromColumns($columns, [], ListingStatus::Active);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertNotEmpty($result->get_error_data());
        self::assertSame(0, $this->listings->countAll(), 'validation runs before persistence');
        self::assertSame([], $this->prices->entries);
    }

    public function testInvalidRevisionLeavesStoredDataUntouched(): void
    {
        $created = $this->ingest->createFromColumns($this->validColumns(), [], ListingStatus::Active);
        self::assertNotInstanceOf(\WP_Error::class, $created);
        $historyBefore = count($this->prices->entries);

        $result = $this->ingest->reviseFromColumns($created, [
            'price_amount' => '900000',
            'specifications' => [], // invalid: drops power_or_sail
        ], []);

        self::assertInstanceOf(\WP_Error::class, $result);
        $stored = $this->listings->find($created->id);
        self::assertSame('1000000', $stored?->priceAmount, 'stored price unchanged');
        self::assertSame($created->federationUpdatedAt, $stored?->federationUpdatedAt, 'no updated_at bump on refusal');
        self::assertCount($historyBefore, $this->prices->entries, 'no spurious price history');
    }

    public function testValidRevisionAppliesAndReplacesMedia(): void
    {
        $created = $this->ingest->createFromColumns($this->validColumns(), [[
            'kind' => 'gallery', 'url' => 'https://node.test/a.jpg', 'sha256' => null, 'sort' => 1,
        ]], ListingStatus::Active);
        self::assertNotInstanceOf(\WP_Error::class, $created);

        $result = $this->ingest->reviseFromColumns($created, ['price_amount' => '900000'], [[
            'kind' => 'gallery', 'url' => 'https://node.test/b.jpg', 'sha256' => null, 'sort' => 1,
        ]]);

        self::assertNotInstanceOf(\WP_Error::class, $result);
        self::assertSame('900000', $result->priceAmount);
        $media = $this->media->forListing($created->id);
        self::assertCount(1, $media);
        self::assertSame('https://node.test/b.jpg', $media[0]->url);
        self::assertCount(2, $this->prices->entries, 'price change appends history');
    }

    public function testWireShapedPublishStillWorks(): void
    {
        $result = $this->ingest->publish([
            'status' => 'active',
            'vessel' => ['builder' => ['name' => 'Feadship', 'slug' => 'feadship']],
            'listing' => ['name' => 'WIRE TEST', 'price' => ['amount' => '2000000', 'currency' => 'USD']],
            'specifications' => ['power_or_sail' => 'power'],
            'descriptions' => [['section' => 'overview', 'content' => '<p>ok</p><script>bad()</script>']],
        ]);

        self::assertNotInstanceOf(\WP_Error::class, $result);
        self::assertSame('WIRE TEST', $result->name);
        self::assertStringNotContainsString('script', (string) json_encode($result->descriptions), 'descriptions sanitised on input (LS-4)');
    }
}

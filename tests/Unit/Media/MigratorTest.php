<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Media;

use OpenYacht\Federation\ListingCopy;
use OpenYacht\Media\Migrator;
use OpenYacht\Tests\Support\FakeStorage;
use OpenYacht\Tests\Support\InMemoryCopyMediaRepository;
use OpenYacht\Tests\Support\InMemoryCopyRepository;
use OpenYacht\Tests\Unit\TestCase;

final class MigratorTest extends TestCase
{
    private InMemoryCopyRepository $copies;

    private InMemoryCopyMediaRepository $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->copies = new InMemoryCopyRepository();
        $this->media = new InMemoryCopyMediaRepository();
    }

    private function seedCopy(int $id, array $renditionPaths): void
    {
        $this->copies->copies[$id] = new ListingCopy(
            id: $id,
            partnerId: 1,
            canonicalUri: "https://authority.example/openyacht/v1/listings/{$id}",
            authorityDomain: 'authority.example',
            type: 'sale',
            status: 'active',
            name: 'VESSEL',
            payload: [],
            listingUpdatedAt: null,
            receivedAt: '2026-08-24 12:00:00',
            signatureVerified: true,
            tombstonedAt: null,
        );

        $renditions = [];

        foreach ($renditionPaths as $key => $path) {
            $renditions[$key] = ['path' => $path, 'width' => 480, 'height' => 270];
        }

        $this->media->insert($id, 'gallery', 'https://authority.example/img.jpg', null, null, 0, $renditions);
    }

    public function testCopiesMissingFilesAndSkipsPresentOnes(): void
    {
        $this->seedCopy(1, ['w480' => '1/aaa-w480.webp', 'w960' => '1/aaa-w960.webp']);
        $this->seedCopy(2, ['w480' => '2/bbb-w480.webp']);

        $from = new FakeStorage();
        $from->files = ['1/aaa-w480.webp' => 'A', '1/aaa-w960.webp' => 'B', '2/bbb-w480.webp' => 'C'];
        $to = new FakeStorage();
        $to->files = ['1/aaa-w480.webp' => 'A'];

        $result = (new Migrator($this->copies, $this->media))->migrate($from, $to);

        self::assertSame(['copied' => 2, 'skipped' => 1, 'failures' => []], $result);
        self::assertSame('B', $to->files['1/aaa-w960.webp']);
        self::assertSame('C', $to->files['2/bbb-w480.webp']);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->seedCopy(1, ['w480' => '1/aaa-w480.webp']);
        $from = new FakeStorage();
        $from->files = ['1/aaa-w480.webp' => 'A'];
        $to = new FakeStorage();

        $result = (new Migrator($this->copies, $this->media))->migrate($from, $to, dryRun: true);

        self::assertSame(1, $result['copied']);
        self::assertSame([], $to->files);
    }

    public function testMissingSourceFileIsAFailureNotFatal(): void
    {
        $this->seedCopy(1, ['w480' => '1/aaa-w480.webp', 'w960' => '1/missing.webp']);
        $from = new FakeStorage();
        $from->files = ['1/aaa-w480.webp' => 'A'];
        $to = new FakeStorage();

        $result = (new Migrator($this->copies, $this->media))->migrate($from, $to);

        self::assertSame(1, $result['copied']);
        self::assertArrayHasKey('1/missing.webp', $result['failures']);
        self::assertArrayNotHasKey('1/missing.webp', $to->files);
    }

    public function testDeleteSourceOnlyRemovesVerifiedCopies(): void
    {
        $this->seedCopy(1, ['w480' => '1/aaa-w480.webp', 'w960' => '1/only-local.webp']);
        $from = new FakeStorage();
        $from->files = ['1/aaa-w480.webp' => 'A', '1/only-local.webp' => 'B'];
        $to = new FakeStorage();
        $to->files = ['1/aaa-w480.webp' => 'A'];

        $deleted = (new Migrator($this->copies, $this->media))->deleteSource($from, $to);

        self::assertSame(1, $deleted);
        self::assertArrayHasKey('1/only-local.webp', $from->files);
    }
}

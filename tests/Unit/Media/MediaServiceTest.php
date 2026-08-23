<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Media;

use Mockery;
use OpenYacht\Federation\ListingCopy;
use OpenYacht\Media\ImageFetcher;
use OpenYacht\Media\MediaFetchException;
use OpenYacht\Media\MediaService;
use OpenYacht\Tests\Support\CollectingLogger;
use OpenYacht\Tests\Support\FakeRenditionGenerator;
use OpenYacht\Tests\Support\FakeStorage;
use OpenYacht\Tests\Support\InMemoryCopyMediaRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('ID-10')]
final class MediaServiceTest extends TestCase
{
    private FakeStorage $storage;

    private InMemoryCopyMediaRepository $media;

    private CollectingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FakeStorage();
        $this->media = new InMemoryCopyMediaRepository();
        $this->logger = new CollectingLogger();
    }

    /**
     * @param array<string, mixed> $mediaBlock
     */
    private function copy(array $mediaBlock, int $id = 1): ListingCopy
    {
        return new ListingCopy(
            id: $id,
            partnerId: 1,
            canonicalUri: "https://authority.example/openyacht/v1/listings/{$id}",
            authorityDomain: 'authority.example',
            type: 'sale',
            status: 'active',
            name: 'VESSEL',
            payload: ['media' => $mediaBlock],
            listingUpdatedAt: null,
            receivedAt: '2026-08-23 12:00:00',
            signatureVerified: true,
            tombstonedAt: null,
        );
    }

    private function service(?ImageFetcher $fetcher = null): MediaService
    {
        if ($fetcher === null) {
            $fetcher = Mockery::mock(ImageFetcher::class);
            $fetcher->shouldReceive('fetch')->andReturnUsing(static fn (string $url): string => 'bytes-of-' . $url);
        }

        return new MediaService($this->storage, $fetcher, new FakeRenditionGenerator(), $this->media, $this->logger);
    }

    public function testSyncCachesProfileAndGalleryWithProfileCrops(): void
    {
        $copy = $this->copy([
            'profile' => ['url' => 'https://cdn.example/hero.jpg', 'sha256' => null, 'caption' => 'Hero', 'thumbnail_url' => 'https://cdn.example/t.jpg'],
            'gallery' => [
                ['url' => 'https://cdn.example/b.jpg', 'sha256' => null, 'sort' => 2],
                ['url' => 'https://cdn.example/a.jpg', 'sha256' => null, 'sort' => 1],
            ],
        ]);

        $count = $this->service()->sync($copy);

        self::assertSame(3, $count);
        $stored = $this->media->forCopy(1);
        self::assertSame(['profile', 'gallery', 'gallery'], array_column(array_map(static fn ($m) => ['kind' => $m->kind], $stored), 'kind'));
        self::assertSame('https://cdn.example/a.jpg', $stored[1]->sourceUrl, 'gallery ordered by sort');
        self::assertArrayHasKey('crop_480', $stored[0]->renditions, 'profile gets hero crops');
        self::assertArrayNotHasKey('crop_480', $stored[1]->renditions);

        foreach ($this->storage->files as $path => $bytes) {
            self::assertStringStartsWith('1/', $path, 'files live under the copy id directory');
        }
    }

    public function testNoResyncWhenWireSetIsUnchanged(): void
    {
        $copy = $this->copy(['profile' => ['url' => 'https://cdn.example/hero.jpg', 'sha256' => 'abc']]);
        $service = $this->service();

        self::assertSame(1, $service->sync($copy));
        self::assertFalse($service->needsSync($copy));
        self::assertSame(0, $service->sync($copy), 'unchanged media set is not re-fetched');
    }

    public function testChangedHashTriggersACleanResync(): void
    {
        $service = $this->service();
        $service->sync($this->copy(['profile' => ['url' => 'https://cdn.example/hero.jpg', 'sha256' => 'aaa']]));

        $updated = $this->copy(['profile' => ['url' => 'https://cdn.example/hero.jpg', 'sha256' => 'bbb']]);

        self::assertTrue($service->needsSync($updated));
        $service->sync($updated);

        self::assertContains('1', $this->storage->deletedDirectories, 'stale files are purged before re-fetch');
        self::assertCount(1, $this->media->forCopy(1));
        self::assertSame('bbb', $this->media->forCopy(1)[0]->sourceSha256);
    }

    public function testOneBadImageIsLoggedAndNeverFailsThePass(): void
    {
        $fetcher = Mockery::mock(ImageFetcher::class);
        $fetcher->shouldReceive('fetch')
            ->twice()
            ->andReturnUsing(static function (string $url): string {
                if (str_contains($url, 'bad')) {
                    throw new MediaFetchException('hash mismatch');
                }

                return 'bytes';
            });

        $copy = $this->copy(['gallery' => [
            ['url' => 'https://cdn.example/bad.jpg', 'sort' => 0],
            ['url' => 'https://cdn.example/good.jpg', 'sort' => 1],
        ]]);

        $count = $this->service($fetcher)->sync($copy);

        self::assertSame(1, $count);
        self::assertNotEmpty(array_filter($this->logger->entries, static fn (array $e): bool => $e['channel'] === 'media' && $e['outcome'] === 'warning'));
    }

    public function testExpireDeletesFilesAndRows(): void
    {
        $copy = $this->copy(['profile' => ['url' => 'https://cdn.example/hero.jpg']]);
        $service = $this->service();
        $service->sync($copy);
        self::assertNotEmpty($this->storage->files);

        $service->expire($copy);

        self::assertSame([], $this->storage->files);
        self::assertSame([], $this->media->forCopy(1));
    }

    public function testUrlsExposeRenditionsForSrcset(): void
    {
        $copy = $this->copy(['profile' => ['url' => 'https://cdn.example/hero.jpg']]);
        $service = $this->service();
        $service->sync($copy);

        $urls = $service->urls($this->media->forCopy(1)[0]);

        self::assertArrayHasKey('w480', $urls);
        self::assertStringStartsWith('https://node.test/wp-content/uploads/openyacht/1/', $urls['w480']['url']);
        self::assertSame(480, $urls['w480']['width']);
    }
}

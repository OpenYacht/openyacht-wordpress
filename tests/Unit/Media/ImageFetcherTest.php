<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Media;

use Brain\Monkey\Functions;
use OpenYacht\Media\ImageFetcher;
use OpenYacht\Media\MediaFetchException;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('FP-14')]
final class ImageFetcherTest extends TestCase
{
    private function stubHttp(string $body, string $contentType = 'image/jpeg', int $status = 200): void
    {
        Functions\when('wp_remote_get')->justReturn([]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_header')->justReturn($contentType);
        Functions\when('wp_remote_retrieve_body')->justReturn($body);
    }

    public function testFetchesAnHttpsImageAndVerifiesItsHash(): void
    {
        $bytes = random_bytes(64);
        $this->stubHttp($bytes);

        $fetched = (new ImageFetcher())->fetch('https://cdn.example/img.jpg', hash('sha256', $bytes));

        self::assertSame($bytes, $fetched);
    }

    public function testHashIsOptionalWhenTheAuthorityPublishedNone(): void
    {
        $this->stubHttp('image-bytes');

        self::assertSame('image-bytes', (new ImageFetcher())->fetch('https://cdn.example/img.jpg', null));
    }

    public function testRefusesPlainHttpUrls(): void
    {
        $this->expectException(MediaFetchException::class);

        (new ImageFetcher())->fetch('http://cdn.example/img.jpg');
    }

    public function testRejectsNonImageContentTypes(): void
    {
        $this->stubHttp('<html></html>', 'text/html');

        $this->expectException(MediaFetchException::class);

        (new ImageFetcher())->fetch('https://cdn.example/img.jpg');
    }

    public function testRejectsAHashMismatch(): void
    {
        $this->stubHttp('tampered-bytes');

        $this->expectException(MediaFetchException::class);

        (new ImageFetcher())->fetch('https://cdn.example/img.jpg', hash('sha256', 'original-bytes'));
    }

    public function testRejectsHttpErrors(): void
    {
        $this->stubHttp('nope', 'image/jpeg', 404);

        $this->expectException(MediaFetchException::class);

        (new ImageFetcher())->fetch('https://cdn.example/img.jpg');
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit;

use OpenYacht\Updates;

final class UpdatesTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function release(array $overrides = []): array
    {
        return array_merge([
            'tag_name' => 'v0.2.0',
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://github.com/OpenYacht/openyacht-wordpress/releases/tag/v0.2.0',
            'body' => 'Adds things.',
            'published_at' => '2026-08-24T12:00:00Z',
            'assets' => [
                ['name' => 'openyacht-0.2.0.zip', 'browser_download_url' => 'https://github.com/OpenYacht/openyacht-wordpress/releases/download/v0.2.0/openyacht-0.2.0.zip'],
            ],
        ], $overrides);
    }

    public function testParsesReleaseAndStripsTagPrefix(): void
    {
        $update = Updates::releaseToUpdate($this->release());

        self::assertNotNull($update);
        self::assertSame('0.2.0', $update['version']);
        self::assertSame('https://github.com/OpenYacht/openyacht-wordpress/releases/download/v0.2.0/openyacht-0.2.0.zip', $update['package']);
        self::assertSame('Adds things.', $update['notes']);
    }

    public function testAcceptsUnprefixedTag(): void
    {
        $release = $this->release(['tag_name' => '0.2.0']);

        self::assertSame('0.2.0', Updates::releaseToUpdate($release)['version'] ?? null);
    }

    public function testRejectsDraftAndPrerelease(): void
    {
        self::assertNull(Updates::releaseToUpdate($this->release(['draft' => true])));
        self::assertNull(Updates::releaseToUpdate($this->release(['prerelease' => true])));
    }

    public function testRejectsNonVersionTag(): void
    {
        self::assertNull(Updates::releaseToUpdate($this->release(['tag_name' => 'nightly'])));
    }

    public function testRejectsReleaseMissingItsBuildAsset(): void
    {
        $wrongName = $this->release(['assets' => [
            ['name' => 'openyacht-0.1.0.zip', 'browser_download_url' => 'https://example.com/openyacht-0.1.0.zip'],
        ]]);

        self::assertNull(Updates::releaseToUpdate($wrongName));
        self::assertNull(Updates::releaseToUpdate($this->release(['assets' => []])));
    }
}

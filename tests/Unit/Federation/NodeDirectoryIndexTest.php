<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use OpenYacht\Federation\NodeDirectoryIndex;
use OpenYacht\Tests\Unit\TestCase;
use RuntimeException;

final class NodeDirectoryIndexTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $storedOption = null;

    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('is_wp_error')->justReturn(false);
        $this->storedOption = null;
        Functions\when('get_option')->alias(fn (string $key, $default = false) => $this->storedOption ?? $default);
        Functions\when('update_option')->alias(function (string $key, $value): bool {
            $this->storedOption = $value;

            return true;
        });
    }

    private function index(): NodeDirectoryIndex
    {
        return new NodeDirectoryIndex(dirname(__DIR__, 3) . '/resources/registry/nodes.json');
    }

    /**
     * @param array<string, mixed> $document
     */
    private function stubFetch(array $document, int $status = 200): void
    {
        Functions\when('wp_remote_get')->justReturn(['body' => wp_json_encode($document)]);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_body')->justReturn(json_encode($document));
    }

    public function testVendoredCopyServesWhenNothingIsCached(): void
    {
        self::assertSame([], $this->index()->entries(), 'the published directory ships empty');
        self::assertNull($this->index()->fetchedAt());
    }

    public function testRefreshCachesValidEntriesAndDropsMalformedOnes(): void
    {
        $this->stubFetch([
            'registry' => 'openyacht-nodes',
            'version' => '2026.08.1',
            'nodes' => [
                ['domain' => 'Broker.Example', 'name' => 'Broker One', 'website' => 'https://brokerone.example', 'country' => 'US', 'listed_at' => '2026-08-23'],
                ['domain' => 'no-tld', 'name' => 'Bad', 'website' => 'https://bad.example', 'country' => 'US', 'listed_at' => '2026-08-23'],
                ['domain' => 'http.example', 'name' => 'Bad', 'website' => 'http://insecure.example', 'country' => 'US', 'listed_at' => '2026-08-23'],
                ['domain' => 'country.example', 'name' => 'Bad', 'website' => 'https://x.example', 'country' => 'usa', 'listed_at' => '2026-08-23'],
            ],
        ]);

        self::assertSame(1, $this->index()->refresh(), 'only the well-formed entry survives');

        $entries = $this->index()->entries();
        self::assertCount(1, $entries);
        self::assertSame('broker.example', $entries[0]['domain'], 'domains are lowercased');
        self::assertNotNull($this->index()->fetchedAt());
    }

    public function testABadResponseNeverReplacesTheCache(): void
    {
        $this->stubFetch(['registry' => 'openyacht-nodes', 'nodes' => [['domain' => 'good.example', 'name' => 'Good', 'website' => 'https://good.example', 'country' => 'FR', 'listed_at' => '2026-08-23']]]);
        $this->index()->refresh();

        $this->stubFetch(['registry' => 'something-else', 'nodes' => []]);

        try {
            $this->index()->refresh();
            self::fail('a wrong registry constant must throw');
        } catch (RuntimeException) {
        }

        self::assertCount(1, $this->index()->entries(), 'the previous good cache survives');
    }
}

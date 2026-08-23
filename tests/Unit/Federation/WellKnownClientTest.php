<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use OpenYacht\Federation\InvalidWellKnownDocument;
use OpenYacht\Federation\WellKnownClient;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('FP-2')]
final class WellKnownClientTest extends TestCase
{
    /**
     * @param array<string, mixed>|string $body
     */
    private function stubHttp(array|string $body, int $status = 200): void
    {
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($status);
        Functions\when('wp_remote_retrieve_body')->justReturn(is_string($body) ? $body : (string) json_encode($body));
    }

    public function testFetchesOverHttpsWithStrictTlsAndValidates(): void
    {
        $document = [
            'openyacht' => '1.0',
            'node' => ['uuid' => 'aaaaaaaa-1111-2222-3333-444444444444'],
            'keys' => [['key_id' => str_repeat('a', 16), 'public_key' => 'x']],
        ];

        Functions\expect('wp_remote_get')
            ->once()
            ->with('https://broker.example/.well-known/openyacht', ['timeout' => 15, 'sslverify' => true])
            ->andReturn(['response' => ['code' => 200]]);
        $this->stubHttp($document);

        self::assertSame($document, (new WellKnownClient())->fetch('broker.example'));
    }

    public function testRejectsNonJsonBodies(): void
    {
        Functions\when('wp_remote_get')->justReturn([]);
        $this->stubHttp('<html>not json</html>');

        $this->expectException(InvalidWellKnownDocument::class);

        (new WellKnownClient())->fetch('broker.example');
    }

    public function testRejectsDocumentsMissingRequiredFields(): void
    {
        Functions\when('wp_remote_get')->justReturn([]);
        $this->stubHttp(['openyacht' => '1.0', 'node' => ['uuid' => 'u'], 'keys' => []]);

        $this->expectException(InvalidWellKnownDocument::class);

        (new WellKnownClient())->fetch('broker.example');
    }

    public function testRejectsHttpErrorStatuses(): void
    {
        Functions\when('wp_remote_get')->justReturn([]);
        $this->stubHttp(['anything' => true], 404);

        $this->expectException(InvalidWellKnownDocument::class);

        (new WellKnownClient())->fetch('broker.example');
    }
}

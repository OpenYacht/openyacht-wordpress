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
        // A real Ed25519 key whose key_id is the FP-3 digest of its
        // material — ingest now binds the two, so a placeholder key_id no
        // longer passes (see testRejectsKeysWhoseIdDoesNotMatchMaterial).
        $document = [
            'openyacht' => '1.0',
            'node' => ['uuid' => 'aaaaaaaa-1111-2222-3333-444444444444'],
            'keys' => [[
                'key_id' => '25f0c5c537a07c58',
                'algorithm' => 'ed25519',
                'public_key' => 'QKcwbi+S0spqvUIba9P45r2SDvKqbXmjCb6zsTn51Ac=',
            ]],
        ];

        Functions\expect('wp_remote_get')
            ->once()
            ->with('https://broker.example/.well-known/openyacht', ['timeout' => 15, 'sslverify' => true, 'redirection' => 0])
            ->andReturn(['response' => ['code' => 200]]);
        $this->stubHttp($document);

        self::assertSame($document, (new WellKnownClient())->fetch('broker.example'));
    }

    public function testRejectsKeysWhoseIdDoesNotMatchMaterial(): void
    {
        // The key material is real but the key_id is an attacker-chosen
        // label: binding the id to sha256(public_key) is what makes a pin a
        // pin on a key rather than on a relabelable string (FP-3).
        Functions\when('wp_remote_get')->justReturn([]);
        $this->stubHttp([
            'openyacht' => '1.0',
            'node' => ['uuid' => 'aaaaaaaa-1111-2222-3333-444444444444'],
            'keys' => [[
                'key_id' => str_repeat('a', 16),
                'algorithm' => 'ed25519',
                'public_key' => 'QKcwbi+S0spqvUIba9P45r2SDvKqbXmjCb6zsTn51Ac=',
            ]],
        ]);

        $this->expectException(InvalidWellKnownDocument::class);

        (new WellKnownClient())->fetch('broker.example');
    }

    public function testRefusesSsrfHostBeforeAnyRequest(): void
    {
        // An IP literal (cloud metadata / loopback) must be refused before
        // the outbound request is ever made (FP-14).
        Functions\expect('wp_remote_get')->never();

        $this->expectException(InvalidWellKnownDocument::class);

        (new WellKnownClient())->fetch('169.254.169.254');
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

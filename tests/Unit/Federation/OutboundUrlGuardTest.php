<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use OpenYacht\Federation\BlockedOutboundHost;
use OpenYacht\Federation\OutboundUrlGuard;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The outbound guard is the SSRF chokepoint for every federation fetch:
 * well-known discovery, signed sync, and media import all pass their host
 * through it. The shape checks below reject before any DNS lookup, so they
 * are hermetic.
 *
 * // federation-protocol.md §Untrusted Input (FP-14)
 */
#[Group('FP-14')]
final class OutboundUrlGuardTest extends TestCase
{
    private OutboundUrlGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new OutboundUrlGuard();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ssrfHosts(): array
    {
        return [
            'ipv4 loopback' => ['127.0.0.1'],
            'cloud metadata' => ['169.254.169.254'],
            'ipv6 loopback' => ['::1'],
            'private range' => ['10.0.0.5'],
            'no dot / localhost' => ['localhost'],
            'trailing path' => ['evil.example/path'],
            'embedded port' => ['evil.example:8080'],
            'userinfo trick' => ['evil.example@127.0.0.1'],
        ];
    }

    #[DataProvider('ssrfHosts')]
    public function testRejectsTheSsrfHostShapesAnAttackerWouldUse(string $host): void
    {
        $this->expectException(BlockedOutboundHost::class);

        $this->guard->assertPublicHost($host);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ssrfUrls(): array
    {
        return [
            'plain http' => ['http://partner.example/x'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://127.0.0.1:6379/x'],
            'ip literal host' => ['https://169.254.169.254/latest/meta-data/'],
            'internal port' => ['https://127.0.0.1:6379/x'],
            'userinfo host' => ['https://partner.example@127.0.0.1/x'],
        ];
    }

    #[DataProvider('ssrfUrls')]
    public function testRejectsNonHttpsAndHostSmugglingUrls(string $url): void
    {
        $this->expectException(BlockedOutboundHost::class);

        $this->guard->assertPublicHttpsUrl($url);
    }

    public function testAllowsAWellFormedPublicHostname(): void
    {
        // `.example` is reserved and never resolves, so this exercises the
        // shape gate without a real DNS dependency: an unresolvable name is
        // left to fail at connect time, not blocked here.
        $this->guard->assertPublicHost('broker.example');
        $this->guard->assertPublicHttpsUrl('https://broker.example/openyacht/v1/listings');

        $this->addToAssertionCount(1);
    }
}

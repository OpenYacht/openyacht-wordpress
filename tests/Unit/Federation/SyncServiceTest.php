<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Actions;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\SyncService;
use OpenYacht\Federation\TrustLevel;
use OpenYacht\Tests\Support\FakeFederationClient;
use OpenYacht\Tests\Support\InMemoryCopyRepository;
use OpenYacht\Tests\Support\InMemoryPartnerRepository;
use OpenYacht\Tests\Unit\TestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class SyncServiceTest extends TestCase
{
    private InMemoryPartnerRepository $partners;

    private InMemoryCopyRepository $copies;

    private FakeFederationClient $client;

    private SyncService $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->partners = new InMemoryPartnerRepository();
        $this->copies = new InMemoryCopyRepository();
        $this->client = new FakeFederationClient();
        $this->sync = new SyncService($this->client, $this->partners, $this->copies);
    }

    private function partner(array $overrides = []): Partner
    {
        return $this->partners->insert($overrides + [
            'domain' => 'authority.example',
            'trust_level' => TrustLevel::Verified,
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function page(array $items, ?string $nextCursor = null, string $generatedAt = '2026-08-23T10:00:00Z'): array
    {
        $meta = ['generated_at' => $generatedAt, 'protocol_version' => '1.0'];

        if ($nextCursor !== null) {
            $meta['next_cursor'] = $nextCursor;
        }

        return ['data' => $items, 'meta' => $meta];
    }

    private function listing(string $uuid, string $name = 'VESSEL'): array
    {
        return [
            'id' => "https://authority.example/openyacht/v1/listings/{$uuid}",
            'type' => 'sale',
            'status' => 'active',
            'updated_at' => '2026-08-23T09:00:00Z',
            'listing' => ['name' => $name],
        ];
    }

    #[Group('API-2')]
    #[Group('ID-2')]
    #[Group('ID-3')]
    public function testColdSyncFollowsCursorsAndStoresWatermarkFromGeneratedAt(): void
    {
        Actions\expectDone('openyacht_copy_created')->times(3);

        $partner = $this->partner();
        $this->client->queueJson($this->page([$this->listing('aaa'), $this->listing('bbb')], nextCursor: 'CURSOR-2'));
        $this->client->queueJson($this->page([$this->listing('ccc')]));

        $result = $this->sync->sync($partner);

        self::assertSame(3, $result->created);
        self::assertCount(2, $this->client->requestedPaths);
        self::assertStringNotContainsString('updated_since', $this->client->requestedPaths[0], 'cold sync sends no watermark');
        self::assertStringContainsString('cursor=CURSOR-2', $this->client->requestedPaths[1]);

        $fresh = $this->partners->find($partner->id);
        self::assertSame('2026-08-23 10:00:00', $fresh?->lastSyncedAt, 'watermark comes from meta.generated_at, not local time');
        self::assertSame(0, $fresh?->consecutiveFailures);

        $copy = $this->copies->findForPartner($partner->id, 'https://authority.example/openyacht/v1/listings/aaa');
        self::assertNotNull($copy);
        self::assertTrue($copy->signatureVerified);
        self::assertSame('authority.example', $copy->authorityDomain);
    }

    #[Group('API-2')]
    public function testIncrementalSyncSendsTheStoredWatermark(): void
    {
        Actions\expectDone('openyacht_copy_updated')->once();

        $partner = $this->partner(['last_synced_at' => '2026-08-20 08:00:00']);
        $this->copies->upsert($partner, 'https://authority.example/openyacht/v1/listings/aaa', $this->listing('aaa'));
        $this->client->queueJson($this->page([$this->listing('aaa', 'RENAMED')]));

        $result = $this->sync->sync($partner);

        self::assertSame(1, $result->updated);
        self::assertStringContainsString('updated_since=2026-08-20T08%3A00%3A00Z', $this->client->requestedPaths[0]);
        self::assertSame('RENAMED', $this->copies->findForPartner($partner->id, 'https://authority.example/openyacht/v1/listings/aaa')?->name);
    }

    #[Group('API-3')]
    #[Group('ID-7')]
    public function testTombstoneMarksTheCopyAndFiresTheAction(): void
    {
        Actions\expectDone('openyacht_copy_created')->once();
        Actions\expectDone('openyacht_copy_tombstoned')->once();

        $partner = $this->partner();
        $uri = 'https://authority.example/openyacht/v1/listings/aaa';
        $this->client->queueJson($this->page([$this->listing('aaa')]));
        $this->sync->sync($partner);

        $this->client->queueJson($this->page([[
            'id' => $uri,
            'tombstone' => true,
            'status' => 'sold',
            'updated_at' => '2026-08-23T11:00:00Z',
        ]]));

        $result = $this->sync->sync($partner);

        self::assertSame(1, $result->tombstoned);
        $copy = $this->copies->findForPartner($partner->id, $uri);
        self::assertSame('sold', $copy?->status);
        self::assertNotNull($copy?->tombstonedAt);
    }

    #[Group('API-8')]
    public function testUnknownItemShapesAreSkippedWithoutErroring(): void
    {
        $partner = $this->partner();
        $this->client->queueJson($this->page([
            ['no_id_at_all' => true],
            $this->listing('aaa') + ['x_future_extension' => ['nested' => true]],
        ]));

        Actions\expectDone('openyacht_copy_created')->once();

        $result = $this->sync->sync($partner);

        self::assertSame(1, $result->created);
    }

    public function testHttpFailureIncrementsConsecutiveFailures(): void
    {
        $partner = $this->partner();
        $this->client->queue[] = new \OpenYacht\Federation\HttpResponse(401, '{"error":{"code":"PARTNER_UNKNOWN","message":"..."}}');

        try {
            $this->sync->sync($partner);
            self::fail('expected the sync to throw');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('HTTP 401', $exception->getMessage());
        }

        $fresh = $this->partners->find($partner->id);
        self::assertSame(1, $fresh?->consecutiveFailures);
        self::assertNotNull($fresh?->lastAttemptedAt);
    }

    public function testBackoffDoublesPerFailureAndCapsAtADay(): void
    {
        $base = strtotime('2026-08-23 00:00:00 UTC');
        $partner = fn (int $failures) => $this->partners->insert([
            'domain' => "p{$failures}.example",
            'consecutive_failures' => $failures,
            'last_attempted_at' => '2026-08-23 00:00:00',
        ]);

        // 1 failure -> 1h delay
        self::assertFalse($this->sync->isDue($partner(1), $base + 3599));
        self::assertTrue($this->sync->isDue($partner(1), $base + 3601));
        // 3 failures -> 4h delay
        self::assertFalse($this->sync->isDue($partner(3), $base + 4 * 3600 - 1));
        self::assertTrue($this->sync->isDue($partner(3), $base + 4 * 3600 + 1));
        // 10 failures -> capped at 24h
        self::assertFalse($this->sync->isDue($partner(10), $base + 86399));
        self::assertTrue($this->sync->isDue($partner(10), $base + 86401));
        // no failures -> always due
        self::assertTrue($this->sync->isDue($this->partners->insert(['domain' => 'ok.example']), $base));
    }
}

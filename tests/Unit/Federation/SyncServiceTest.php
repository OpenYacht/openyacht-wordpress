<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Actions;
use OpenYacht\Federation\Partner;
use OpenYacht\Federation\PartnerAwaitingApproval;
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

    private function tombstone(string $uuid, string $updatedAt = '2026-08-24T09:00:00Z'): array
    {
        return [
            'id' => "https://authority.example/openyacht/v1/listings/{$uuid}",
            'tombstone' => true,
            'status' => 'withdrawn',
            'updated_at' => $updatedAt,
        ];
    }

    #[Group('API-11')]
    #[Group('ID-3')]
    public function testAPushedListingIsAppliedLikeAPolledOne(): void
    {
        Actions\expectDone('openyacht_copy_created')->once();
        $partner = $this->partner();

        self::assertSame('created', $this->sync->applyDelivery($partner, $this->listing('aaa', 'OASIS')));

        $copy = $this->copies->findForPartner($partner->id, 'https://authority.example/openyacht/v1/listings/aaa');
        self::assertSame('OASIS', $copy?->name);
        self::assertTrue($copy?->signatureVerified);
    }

    #[Group('API-11')]
    public function testAReplayedDeliveryIsADuplicateButANewerOneIsAChange(): void
    {
        Actions\expectDone('openyacht_copy_created')->once();
        Actions\expectDone('openyacht_copy_updated')->once();
        $partner = $this->partner();

        $this->sync->applyDelivery($partner, $this->listing('aaa'));
        self::assertSame('duplicate', $this->sync->applyDelivery($partner, $this->listing('aaa')));

        $newer = $this->listing('aaa', 'OASIS II');
        $newer['updated_at'] = '2026-08-23T11:00:00Z';
        self::assertSame('updated', $this->sync->applyDelivery($partner, $newer));
        self::assertSame('OASIS II', $this->copies->findForPartner($partner->id, $newer['id'])?->name);
    }

    #[Group('API-11')]
    #[Group('ID-7')]
    public function testATombstoneWithdrawsTheCopyAndItsReplayIsADuplicate(): void
    {
        Actions\expectDone('openyacht_copy_created')->once();
        Actions\expectDone('openyacht_copy_tombstoned')->once();
        $partner = $this->partner();
        $this->sync->applyDelivery($partner, $this->listing('aaa'));

        self::assertSame('tombstoned', $this->sync->applyDelivery($partner, $this->tombstone('aaa')));
        self::assertNotNull($this->copies->findForPartner($partner->id, $this->tombstone('aaa')['id'])?->tombstonedAt);
        self::assertSame('duplicate', $this->sync->applyDelivery($partner, $this->tombstone('aaa')));
    }

    #[Group('API-11')]
    #[Group('API-3')]
    public function testAReSharedListingArrivingWithItsOriginalUpdatedAtAfterATombstoneIsApplied(): void
    {
        // The authority re-shared it: the feed (and so the push) carries
        // the listing's own updated_at again — the very pair a receipt log
        // would have recorded before the tombstone. The copy's state, not a
        // receipt log, decides a repeat.
        Actions\expectDone('openyacht_copy_created')->once();
        Actions\expectDone('openyacht_copy_tombstoned')->once();
        Actions\expectDone('openyacht_copy_updated')->once();
        $partner = $this->partner();
        $this->sync->applyDelivery($partner, $this->listing('aaa'));
        $this->sync->applyDelivery($partner, $this->tombstone('aaa'));

        self::assertSame('updated', $this->sync->applyDelivery($partner, $this->listing('aaa')));
        self::assertNull($this->copies->findForPartner($partner->id, $this->listing('aaa')['id'])?->tombstonedAt);
    }

    #[Group('API-11')]
    public function testAPushSubscribedPartnerIsStillPolledDailyToReconcile(): void
    {
        $now = 1_800_000_000;
        $at = static fn (int $secondsAgo): string => gmdate('Y-m-d H:i:s', $now - $secondsAgo);

        $polled = $this->partner(['domain' => 'polled.example', 'last_ok_at' => $at(2 * 3600)]);
        $recent = $this->partner(['domain' => 'recent.example', 'push_subscribed_at' => $at(0), 'last_ok_at' => $at(2 * 3600)]);
        $dayAgo = $this->partner(['domain' => 'day.example', 'push_subscribed_at' => $at(0), 'last_ok_at' => $at(24 * 3600)]);
        $almostADay = $this->partner(['domain' => 'almost.example', 'push_subscribed_at' => $at(0), 'last_ok_at' => $at(24 * 3600 - 4 * 60)]);
        $neverPolled = $this->partner(['domain' => 'never.example', 'push_subscribed_at' => $at(0), 'last_ok_at' => null]);
        $failing = $this->partner([
            'domain' => 'failing.example',
            'push_subscribed_at' => $at(0),
            'last_ok_at' => $at(3 * 86400),
            'last_attempted_at' => $at(10 * 60),
            'consecutive_failures' => 2,
        ]);

        self::assertTrue($this->sync->isDue($polled, $now), 'an unsubscribed partner polls every pass');
        self::assertFalse($this->sync->isDue($recent, $now), 'subscribed and reconciled two hours ago');
        self::assertTrue($this->sync->isDue($dayAgo, $now), 'the daily reconciliation is due');
        self::assertTrue($this->sync->isDue($almostADay, $now), 'a few minutes early still counts as the daily pass');
        self::assertTrue($this->sync->isDue($neverPolled, $now), 'never reconciled at all');
        self::assertFalse($this->sync->isDue($failing, $now), 'the failure backoff applies first');
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

    #[Group('FP-13')]
    public function testAwaitingTheirApprovalIsNotASyncFailure(): void
    {
        // Their authority answered correctly: delivered, verified, refused
        // because no human has approved us yet. Backing off would leave
        // this node idle for hours after they finally click approve.
        $partner = $this->partner();
        $this->client->queueJson(['error' => ['code' => 'PARTNER_PROVISIONAL', 'message' => 'Partnership is pending approval; no listings are shared yet.']], 403);

        try {
            $this->sync->sync($partner);
            self::fail('expected PartnerAwaitingApproval');
        } catch (PartnerAwaitingApproval $awaiting) {
            self::assertStringContainsString('authority.example', $awaiting->getMessage());
        }

        $fresh = $this->partners->find($partner->id);
        self::assertSame(0, $fresh?->consecutiveFailures, 'no failure recorded');
        self::assertNotNull($fresh?->lastAttemptedAt, 'the attempt itself is still recorded');
        self::assertTrue($this->sync->isDue($fresh), 'polling continues on the normal schedule');
    }

    #[Group('FP-13')]
    public function testAnyOtherRefusalStillCountsAndBacksOff(): void
    {
        $partner = $this->partner();
        $this->client->queueJson(['error' => ['code' => 'PARTNER_BLOCKED', 'message' => 'This partner is blocked.']], 403);

        try {
            $this->sync->sync($partner);
            self::fail('expected a sync failure');
        } catch (PartnerAwaitingApproval) {
            self::fail('PARTNER_BLOCKED must not be read as awaiting approval');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(1, $this->partners->find($partner->id)?->consecutiveFailures);
    }
}

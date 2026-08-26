<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit;

use Brain\Monkey\Functions;
use OpenYacht\Schema;

final class SchemaTest extends TestCase
{
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new \wpdb();
    }

    public function testDefinesEveryDeclaredTable(): void
    {
        $tables = (new Schema($this->wpdb))->tables();

        self::assertSame(Schema::TABLE_SUFFIXES, array_keys($tables));

        foreach ($tables as $suffix => $sql) {
            self::assertStringStartsWith("CREATE TABLE wp_openyacht_{$suffix} (", $sql);
            self::assertStringContainsString('PRIMARY KEY  (id)', $sql, "{$suffix}: dbDelta needs the two-space PRIMARY KEY form");
            self::assertStringContainsString('utf8mb4', $sql);
        }
    }

    public function testProtocolCriticalIndexesExist(): void
    {
        $tables = (new Schema($this->wpdb))->tables();

        self::assertStringContainsString('UNIQUE KEY uuid (uuid)', $tables['listings']);
        self::assertStringContainsString('UNIQUE KEY canonical_uri (canonical_uri(191))', $tables['copies']);
        self::assertStringContainsString('UNIQUE KEY domain (domain(191))', $tables['partners']);
        self::assertStringContainsString('UNIQUE KEY key_id (key_id)', $tables['keys']);
        self::assertStringContainsString('KEY federation_updated_at (federation_updated_at)', $tables['listings']);
        self::assertStringContainsString('KEY partner_time (partner_id,occurred_at)', $tables['visibility_events']);
    }

    public function testJsonColumnsAreLongtext(): void
    {
        $tables = (new Schema($this->wpdb))->tables();

        self::assertStringContainsString('payload longtext NOT NULL', $tables['copies']);
        self::assertDoesNotMatchRegularExpression('/\bjson\b/i', implode("\n", $tables), 'native json type churns under dbDelta');
    }

    public function testMaybeUpgradeIsANoOpWhenVersionMatches(): void
    {
        $GLOBALS['openyacht_dbdelta_calls'] = [];

        Functions\expect('get_option')
            ->once()
            ->with(Schema::OPTION, 0)
            ->andReturn(Schema::VERSION);
        Functions\expect('update_option')->never();

        (new Schema($this->wpdb))->maybeUpgrade();

        self::assertSame([], $GLOBALS['openyacht_dbdelta_calls']);
    }

    public function testMaybeUpgradeRunsEveryStatementWhenVersionIsBehind(): void
    {
        $GLOBALS['openyacht_dbdelta_calls'] = [];

        Functions\expect('get_option')
            ->once()
            ->with(Schema::OPTION, 0)
            ->andReturn(Schema::VERSION - 1);
        Functions\expect('update_option')
            ->once()
            ->with(Schema::OPTION, Schema::VERSION, false)
            ->andReturn(true);

        (new Schema($this->wpdb))->maybeUpgrade();

        self::assertCount(count(Schema::TABLE_SUFFIXES), $GLOBALS['openyacht_dbdelta_calls']);
    }

    public function testUpgradeToV6BackfillsGalleryAndLayoutThumbnails(): void
    {
        $GLOBALS['openyacht_dbdelta_calls'] = [];

        Functions\expect('get_option')->once()->with(Schema::OPTION, 0)->andReturn(5);
        Functions\expect('update_option')->once()->andReturn(true);
        // One attachment still has a smaller rendition, one only serves the
        // original — the latter stays null on the wire (LS-16).
        Functions\expect('wp_get_attachment_image_url')
            ->twice()
            ->andReturnUsing(static fn (int $id): string => $id === 7
                ? 'https://node.test/u/aft-768.jpg'
                : 'https://node.test/u/bow.jpg');

        $this->wpdb->results = [
            ['id' => 1, 'attachment_id' => 7, 'url' => 'https://node.test/u/aft.jpg'],
            ['id' => 2, 'attachment_id' => 9, 'url' => 'https://node.test/u/bow.jpg'],
        ];

        (new Schema($this->wpdb))->maybeUpgrade();

        self::assertSame([[
            'table' => 'wp_openyacht_listing_media',
            'data' => ['thumbnail_url' => 'https://node.test/u/aft-768.jpg'],
            'where' => ['id' => 1],
        ]], $this->wpdb->updated, 'only rows with a genuinely smaller rendition are backfilled');
        self::assertNotEmpty($this->stampQueries(), 'a v5 upgrade also stamps affected listings (wire shape changed)');
    }

    public function testUpgradeFromV6StampsListingsWhoseWireShapeChanged(): void
    {
        $GLOBALS['openyacht_dbdelta_calls'] = [];

        Functions\expect('get_option')->once()->with(Schema::OPTION, 0)->andReturn(6);
        Functions\expect('update_option')->once()->andReturn(true);
        // Thumbnails were already backfilled by v6 — no attachment lookups.
        Functions\expect('wp_get_attachment_image_url')->never();

        (new Schema($this->wpdb))->maybeUpgrade();

        $stamps = $this->stampQueries();
        self::assertCount(1, $stamps);
        self::assertStringContainsString("m.kind IN ('gallery', 'layout')", $stamps[0], 'only listings whose wire representation changed are re-delivered');
    }

    /**
     * @return list<string>
     */
    private function stampQueries(): array
    {
        return array_values(array_filter(
            $this->wpdb->queries,
            static fn (string $sql): bool => str_contains($sql, 'federation_updated_at') && str_starts_with($sql, 'UPDATE wp_openyacht_listings'),
        ));
    }

    public function testUninstallDropsExactlyTheDeclaredTables(): void
    {
        // Guard against the Yachtfolio cleanup-drift bug: uninstall.php must
        // reference the Schema table registry, never its own list.
        $uninstall = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');

        self::assertStringContainsString('Schema::TABLE_SUFFIXES', $uninstall);
        self::assertStringContainsString('NodeIdentity::OPTION', $uninstall);
        self::assertStringContainsString('Schema::OPTION', $uninstall);
    }
}

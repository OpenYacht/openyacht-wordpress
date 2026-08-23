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

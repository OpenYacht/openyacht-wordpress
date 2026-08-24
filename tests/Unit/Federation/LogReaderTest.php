<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use OpenYacht\Federation\WpdbLogReader;
use OpenYacht\Tests\Unit\TestCase;

final class LogReaderTest extends TestCase
{
    private \wpdb $wpdb;

    private WpdbLogReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wpdb = new \wpdb();
        $this->reader = new WpdbLogReader($this->wpdb);
    }

    public function testRowsQueryAppliesFiltersAndPagination(): void
    {
        $this->reader->rows(['channel' => 'request', 'partner_id' => 7], 50, 3);

        $sql = end($this->wpdb->queries);
        self::assertStringContainsString("channel = 'request'", $sql);
        self::assertStringContainsString('partner_id = 7', $sql);
        self::assertStringContainsString('ORDER BY id DESC LIMIT 50 OFFSET 100', $sql);
    }

    public function testUnfilteredCountSkipsWhereClause(): void
    {
        $this->wpdb->var = '12';

        self::assertSame(12, $this->reader->count());
        self::assertStringNotContainsString('WHERE', (string) end($this->wpdb->queries));
    }

    public function testPruneTargetsOnlyOperationalChannels(): void
    {
        $this->reader->prune(90);

        $sql = end($this->wpdb->queries);
        self::assertStringContainsString("channel IN ('request', 'sync')", $sql);
        self::assertStringContainsString('created_at <', $sql);
        self::assertStringNotContainsString('partner', $sql);
    }

    public function testZeroRetentionNeverDeletes(): void
    {
        self::assertSame(0, $this->reader->prune(0));
        self::assertSame([], $this->wpdb->queries);
    }
}

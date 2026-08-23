<?php

declare(strict_types=1);

/**
 * Minimal stand-in for WordPress's wpdb in the no-WordPress unit lane.
 */
class wpdb
{
    public string $prefix = 'wp_';

    /** @var list<string> */
    public array $queries = [];

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }

    public function query(string $sql): int
    {
        $this->queries[] = $sql;

        return 0;
    }
}

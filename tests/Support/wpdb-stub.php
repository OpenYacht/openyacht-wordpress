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

    /** @var list<array{table: string, data: array<string, mixed>}> */
    public array $inserted = [];

    /** @var array<string, mixed>|null Programmable get_row() result. */
    public ?array $row = null;

    /** @var list<array<string, mixed>> Programmable get_results() result. */
    public array $results = [];

    /** Programmable get_var() result. */
    public mixed $var = null;

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }

    public function query(string $sql): int
    {
        $this->queries[] = $sql;

        return 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $this->inserted[] = ['table' => $table, 'data' => $data];

        return 1;
    }

    public function prepare(string $query, mixed ...$args): string
    {
        $query = str_replace(['%s', '%d'], ["'%s'", '%d'], $query);

        return vsprintf($query, $args);
    }

    public function get_var(string $query): mixed
    {
        $this->queries[] = $query;

        return $this->var;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_row(string $query, string $output = 'OBJECT'): ?array
    {
        $this->queries[] = $query;

        return $this->row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get_results(string $query, string $output = 'OBJECT'): array
    {
        $this->queries[] = $query;

        return $this->results;
    }
}

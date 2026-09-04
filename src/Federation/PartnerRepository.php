<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

interface PartnerRepository
{
    public function find(int $id): ?Partner;

    public function findByDomain(string $domain): ?Partner;

    /** @return list<Partner> */
    public function all(): array;

    /**
     * Partners eligible for syncing: everything not blocked.
     *
     * @return list<Partner>
     */
    public function syncable(): array;

    /**
     * @param array<string, mixed> $columns
     */
    public function insert(array $columns): Partner;

    /**
     * @param array<string, mixed> $columns
     */
    public function update(int $id, array $columns): void;

    public function incrementFailures(int $id): void;
}

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

    /**
     * Delete the partner and every row that hangs off it: audience
     * selections, group memberships and visibility events. Callers gate on
     * PartnerService::isRemovable() — copies are never cascaded. Log rows
     * keep their partner_id on purpose (the audit trail survives).
     */
    public function delete(int $id): void;
}

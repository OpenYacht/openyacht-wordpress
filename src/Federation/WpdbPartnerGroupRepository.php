<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Schema;

final class WpdbPartnerGroupRepository implements PartnerGroupRepository
{
    public function __construct(private readonly \wpdb $wpdb)
    {
    }

    public function all(): array
    {
        $rows = $this->wpdb->get_results("SELECT id, name FROM {$this->table('partner_groups')} ORDER BY name", ARRAY_A);

        return array_map(
            static fn (array $row): PartnerGroup => new PartnerGroup((int) $row['id'], (string) $row['name']),
            is_array($rows) ? $rows : [],
        );
    }

    public function find(int $id): ?PartnerGroup
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT id, name FROM {$this->table('partner_groups')} WHERE id = %d",
            $id,
        ), ARRAY_A);

        return is_array($row) ? new PartnerGroup((int) $row['id'], (string) $row['name']) : null;
    }

    public function create(string $name): PartnerGroup
    {
        $this->wpdb->insert($this->table('partner_groups'), [
            'name' => $name,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);

        return new PartnerGroup((int) $this->wpdb->insert_id, $name);
    }

    public function rename(int $id, string $name): void
    {
        $this->wpdb->update($this->table('partner_groups'), ['name' => $name], ['id' => $id]);
    }

    public function delete(int $id): void
    {
        $this->wpdb->delete($this->table('partner_groups'), ['id' => $id]);
        $this->wpdb->delete($this->table('partner_group_members'), ['group_id' => $id]);
        $this->wpdb->delete($this->table('listing_audience_groups'), ['group_id' => $id]);
    }

    public function members(int $groupId): array
    {
        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT partner_id FROM {$this->table('partner_group_members')} WHERE group_id = %d",
            $groupId,
        ));

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function replaceMembers(int $groupId, array $partnerIds): void
    {
        $this->wpdb->delete($this->table('partner_group_members'), ['group_id' => $groupId]);

        foreach (array_unique(array_map('intval', $partnerIds)) as $partnerId) {
            $this->wpdb->insert($this->table('partner_group_members'), [
                'group_id' => $groupId,
                'partner_id' => $partnerId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    public function groupIdsForPartner(int $partnerId): array
    {
        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT group_id FROM {$this->table('partner_group_members')} WHERE partner_id = %d",
            $partnerId,
        ));

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function groupIdsForListing(int $listingId): array
    {
        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT group_id FROM {$this->table('listing_audience_groups')} WHERE listing_id = %d",
            $listingId,
        ));

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    public function replaceForListing(int $listingId, array $groupIds): void
    {
        $this->wpdb->delete($this->table('listing_audience_groups'), ['listing_id' => $listingId]);

        foreach (array_unique(array_map('intval', $groupIds)) as $groupId) {
            $this->wpdb->insert($this->table('listing_audience_groups'), [
                'listing_id' => $listingId,
                'group_id' => $groupId,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    public function listingIdsSelectingGroup(int $groupId): array
    {
        $ids = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT listing_id FROM {$this->table('listing_audience_groups')} WHERE group_id = %d",
            $groupId,
        ));

        return array_map('intval', is_array($ids) ? $ids : []);
    }

    private function table(string $suffix): string
    {
        return (new Schema($this->wpdb))->tableName($suffix);
    }
}

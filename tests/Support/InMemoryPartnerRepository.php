<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Support;

use OpenYacht\Federation\Partner;
use OpenYacht\Federation\PartnerRepository;
use OpenYacht\Federation\SharingScope;
use OpenYacht\Federation\TrustLevel;

final class InMemoryPartnerRepository implements PartnerRepository
{
    /** @var array<int, Partner> */
    public array $partners = [];

    private int $nextId = 1;

    public function find(int $id): ?Partner
    {
        return $this->partners[$id] ?? null;
    }

    public function findByDomain(string $domain): ?Partner
    {
        foreach ($this->partners as $partner) {
            if ($partner->domain === strtolower(trim($domain))) {
                return $partner;
            }
        }

        return null;
    }

    public function all(): array
    {
        return array_values($this->partners);
    }

    public function syncable(): array
    {
        return array_values(array_filter(
            $this->partners,
            static fn (Partner $partner): bool => $partner->trustLevel !== TrustLevel::Blocked,
        ));
    }

    public function insert(array $columns): Partner
    {
        $partner = $this->buildPartner($this->nextId++, $columns);
        $this->partners[$partner->id] = $partner;

        return $partner;
    }

    public function update(int $id, array $columns): void
    {
        $existing = $this->partners[$id] ?? null;

        if ($existing === null) {
            return;
        }

        $this->partners[$id] = $this->buildPartner($id, $columns + $this->toColumns($existing));
    }

    public function incrementFailures(int $id): void
    {
        $existing = $this->partners[$id] ?? null;

        if ($existing !== null) {
            $this->update($id, ['consecutive_failures' => $existing->consecutiveFailures + 1]);
        }
    }

    public function delete(int $id): void
    {
        unset($this->partners[$id]);
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function buildPartner(int $id, array $columns): Partner
    {
        $trust = $columns['trust_level'] ?? TrustLevel::Provisional;
        $scope = $columns['sharing_scope'] ?? SharingScope::Standard;

        return new Partner(
            id: $id,
            domain: (string) $columns['domain'],
            nodeUuid: $columns['node_uuid'] ?? null,
            keysJson: $columns['keys_json'] ?? null,
            keysFetchedAt: $columns['keys_fetched_at'] ?? null,
            pinnedKeyId: $columns['pinned_key_id'] ?? null,
            trustLevel: $trust instanceof TrustLevel ? $trust : (TrustLevel::tryFrom((string) $trust) ?? TrustLevel::Provisional),
            fieldGroups: $columns['field_groups'] ?? null,
            approvedByUserId: array_key_exists('approved_by_user_id', $columns) && $columns['approved_by_user_id'] !== null
                ? (int) $columns['approved_by_user_id']
                : null,
            lastOkAt: $columns['last_ok_at'] ?? null,
            consecutiveFailures: (int) ($columns['consecutive_failures'] ?? 0),
            lastSyncedAt: $columns['last_synced_at'] ?? null,
            lastAttemptedAt: $columns['last_attempted_at'] ?? null,
            sharingScope: $scope instanceof SharingScope ? $scope : (SharingScope::tryFrom((string) $scope) ?? SharingScope::Standard),
            requestSentAt: $columns['request_sent_at'] ?? null,
            requestMessage: $columns['request_message'] ?? null,
            requestContactEmail: $columns['request_contact_email'] ?? null,
            requestedAt: $columns['requested_at'] ?? null,
            pushCallbackUrl: $columns['push_callback_url'] ?? null,
            pushCallbackRegisteredAt: $columns['push_callback_registered_at'] ?? null,
            pushLastDeliveredAt: $columns['push_last_delivered_at'] ?? null,
            pushLastFailedAt: $columns['push_last_failed_at'] ?? null,
            pushSubscribedAt: $columns['push_subscribed_at'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toColumns(Partner $partner): array
    {
        return [
            'domain' => $partner->domain,
            'node_uuid' => $partner->nodeUuid,
            'keys_json' => $partner->keysJson,
            'keys_fetched_at' => $partner->keysFetchedAt,
            'pinned_key_id' => $partner->pinnedKeyId,
            'trust_level' => $partner->trustLevel,
            'field_groups' => $partner->fieldGroups,
            'approved_by_user_id' => $partner->approvedByUserId,
            'last_ok_at' => $partner->lastOkAt,
            'consecutive_failures' => $partner->consecutiveFailures,
            'last_synced_at' => $partner->lastSyncedAt,
            'last_attempted_at' => $partner->lastAttemptedAt,
            'sharing_scope' => $partner->sharingScope,
            'request_sent_at' => $partner->requestSentAt,
            'request_message' => $partner->requestMessage,
            'request_contact_email' => $partner->requestContactEmail,
            'requested_at' => $partner->requestedAt,
            'push_callback_url' => $partner->pushCallbackUrl,
            'push_callback_registered_at' => $partner->pushCallbackRegisteredAt,
            'push_last_delivered_at' => $partner->pushLastDeliveredAt,
            'push_last_failed_at' => $partner->pushLastFailedAt,
            'push_subscribed_at' => $partner->pushSubscribedAt,
        ];
    }
}

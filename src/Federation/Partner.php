<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * A federation partner: another OpenYacht node this node exchanges
 * listings with. Identity is the domain; the node UUID exists to detect
 * that a domain now hosts a different installation.
 *
 * // federation-protocol.md §Partner Lifecycle
 */
final class Partner
{
    public const STALENESS_DAYS = 7;

    /**
     * @param list<array<string, mixed>>|null $keysJson
     * @param list<string>|null $fieldGroups
     */
    public function __construct(
        public readonly int $id,
        public readonly string $domain,
        public readonly ?string $nodeUuid,
        public readonly ?array $keysJson,
        public readonly ?string $keysFetchedAt,
        public readonly ?string $pinnedKeyId,
        public readonly TrustLevel $trustLevel,
        public readonly ?array $fieldGroups,
        public readonly ?int $approvedByUserId,
        public readonly ?string $lastOkAt,
        public readonly int $consecutiveFailures,
        public readonly ?string $lastSyncedAt,
        public readonly ?string $lastAttemptedAt,
    ) {
    }

    /**
     * The partner's published keys as key_id => base64 public key, the
     * shape the Verifier consumes.
     *
     * @return array<string, string>
     */
    public function publishedKeys(): array
    {
        $keys = [];

        foreach ($this->keysJson ?? [] as $key) {
            if (is_array($key) && is_string($key['key_id'] ?? null) && is_string($key['public_key'] ?? null)) {
                $keys[$key['key_id']] = $key['public_key'];
            }
        }

        return $keys;
    }

    /**
     * The key_id the partner signs with now: the first key in its
     * well-known document, by both implementations' convention. This is the
     * key the TOFU pin is armed to (FP-12). Ingest binds each key_id to its
     * material, so a first-listed key_id is a commitment to a real key.
     */
    public function currentSigningKeyId(): ?string
    {
        $first = $this->keysJson[0] ?? null;

        return is_array($first) && is_string($first['key_id'] ?? null) ? $first['key_id'] : null;
    }

    /**
     * The field groups granted to this partner (LS-14). A null column
     * means every group is granted; an explicit array restricts to those
     * groups.
     *
     * @return list<FieldGroup>
     */
    public function grantedFieldGroups(): array
    {
        if ($this->fieldGroups === null) {
            return FieldGroup::cases();
        }

        $groups = [];

        foreach ($this->fieldGroups as $value) {
            $group = FieldGroup::tryFrom((string) $value);

            if ($group !== null) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    public function hasFieldGroup(FieldGroup $group): bool
    {
        return in_array($group, $this->grantedFieldGroups(), true);
    }

    /**
     * A partner unreachable beyond the staleness threshold marks all its
     * copies stale in any consuming UI (FP-15).
     *
     * // federation-protocol.md §Health and Failure Handling
     */
    public function isStale(?int $nowTimestamp = null): bool
    {
        if ($this->lastOkAt === null) {
            return false;
        }

        $lastOk = strtotime($this->lastOkAt . ' UTC');
        $now = $nowTimestamp ?? time();

        return $lastOk !== false && $lastOk < $now - self::STALENESS_DAYS * 86400;
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Per-listing, per-partner sharing (the design-lead piece the reference
 * app inherits). Audience rules narrow what each partner's feed contains;
 * every visibility change is recorded as an append-only transition so the
 * feed can replay it against any watermark. Unsharing surfaces as a
 * tombstone indistinguishable from withdrawn (no information leak);
 * re-sharing surfaces the listing again.
 *
 * Audience rules compose INSIDE the node's served-set rule (drafts never
 * serve, blocked partners never read) — they only ever narrow it.
 *
 * // wordpress-plugin-notes.md §Granular sharing
 */
final class SharingService
{
    public function __construct(
        private readonly ListingRepository $listings,
        private readonly PartnerRepository $partners,
        private readonly AudienceRepository $audience,
        private readonly PartnerGroupRepository $groups,
        private readonly VisibilityEventRepository $events,
        private readonly Logger $logger,
    ) {
    }

    /**
     * A partner's served view changed wholesale (its field-group grants):
     * lift every visible listing's effective timestamp for that partner so
     * its next poll picks up the re-gated payloads instead of waiting for
     * the next content change (API-4).
     *
     * @return int listings refreshed
     */
    public function refreshPartnerFeed(int $partnerId, string $reason = 'grants changed'): int
    {
        $partner = $this->partners->find($partnerId);

        if ($partner === null) {
            return 0;
        }

        $now = gmdate('Y-m-d H:i:s');
        $refreshed = 0;

        foreach ($this->listings->feedPage($partnerId, $partner->sharingScope, null, null, PHP_INT_MAX - 1) as $item) {
            $this->events->append($item->listing->id, $partnerId, VisibilityEventRepository::REFRESHED, $now);
            $refreshed++;
        }

        if ($refreshed > 0) {
            $this->logger->log('sharing', "Feed refreshed for partner #{$partnerId} ({$reason}): {$refreshed} listing(s) will resend", 'feed_refreshed');
        }

        return $refreshed;
    }

    /**
     * The mirrored visibility predicate (kept in exact agreement with
     * WpdbListingRepository::feedPage()):
     *
     *   visible ⇔ audience ≠ none AND (explicit pivot match
     *             OR (audience = everyone AND partner scope = standard))
     *
     * Explicit pivots — the partner individually selected, or a member of
     * a selected group — are additive: they grant visibility under any
     * audience except none. For a standard partner this is behaviourally
     * identical to the pre-scope rule (the everyone arm absorbs the
     * pivots); for a curated partner the everyone arm drops out, so it
     * sees only what was picked for it.
     */
    public function isVisibleTo(Listing $listing, Partner $partner): bool
    {
        if ($listing->audience === Audience::None) {
            return false;
        }

        if ($listing->audience === Audience::Everyone && $partner->sharingScope === SharingScope::Standard) {
            return true;
        }

        return in_array($partner->id, $this->audience->partnersForListing($listing->id), true)
            || array_intersect($this->groups->groupIdsForListing($listing->id), $this->groups->groupIdsForPartner($partner->id)) !== [];
    }

    /**
     * Change a listing's audience, recording a became-hidden /
     * became-visible transition for every partner whose view changed.
     * An explicit pivot match is the union of individually selected
     * partners and the members of selected groups.
     *
     * The passed selection is the listing's FULL pivot lineup, and it is
     * persisted for 'everyone' as well as 'selected' — under 'everyone' a
     * curated partner is reachable only via a pivot row, so moving to
     * 'everyone' must not wipe the lineup. The one exception is 'none':
     * pivots are left untouched, so temporarily hiding a listing and
     * re-showing it cannot silently drop the curated selection (callers
     * that hide a listing don't submit a picker; the selection args are
     * ignored for 'none').
     *
     * @param list<int> $selectedPartnerIds the full individually-selected lineup
     * @param list<int> $selectedGroupIds the full selected-groups lineup
     * @return array{hidden: int, revealed: int}
     */
    public function setAudience(Listing $listing, Audience $audience, array $selectedPartnerIds = [], array $selectedGroupIds = []): array
    {
        $selectedPartnerIds = array_values(array_unique(array_map('intval', $selectedPartnerIds)));
        $selectedGroupIds = array_values(array_unique(array_map('intval', $selectedGroupIds)));
        $now = gmdate('Y-m-d H:i:s');
        $hidden = 0;
        $revealed = 0;

        $groupMemberIds = [];

        foreach ($selectedGroupIds as $groupId) {
            $groupMemberIds = array_merge($groupMemberIds, $this->groups->members($groupId));
        }

        $visibleAfter = fn (Partner $partner): bool => $audience !== Audience::None
            && (in_array($partner->id, $selectedPartnerIds, true)
                || in_array($partner->id, $groupMemberIds, true)
                || ($audience === Audience::Everyone && $partner->sharingScope === SharingScope::Standard));

        foreach ($this->partners->all() as $partner) {
            // Drafts are never distributed (LS-7): audience changes before
            // first publication need no transitions — no partner ever saw
            // the listing, so there is nothing to tombstone or resurface.
            if ($listing->status === ListingStatus::Draft) {
                break;
            }

            $before = $this->isVisibleTo($listing, $partner);
            $after = $visibleAfter($partner);

            if ($before === $after) {
                continue;
            }

            $this->events->append(
                $listing->id,
                $partner->id,
                $after ? VisibilityEventRepository::VISIBLE : VisibilityEventRepository::HIDDEN,
                $now,
            );
            $after ? $revealed++ : $hidden++;
        }

        if ($audience !== Audience::None) {
            $this->audience->replaceForListing($listing->id, $selectedPartnerIds);
            $this->groups->replaceForListing($listing->id, $selectedGroupIds);
        }

        $this->listings->update($listing->id, ['audience' => $audience->value]);

        if ($hidden + $revealed > 0) {
            $this->logger->log(
                'sharing',
                "Audience for {$listing->uuid} set to {$audience->value}: {$hidden} partner(s) lose visibility, {$revealed} gain it",
                'audience_changed',
            );
        }

        do_action('openyacht_listing_audience_changed', $this->listings->find($listing->id) ?? $listing, $audience);

        return ['hidden' => $hidden, 'revealed' => $revealed];
    }

    /**
     * Change a group's membership, recording visibility transitions for
     * every (listing, partner) pair whose view changes — a partner added
     * to a group immediately receives every listing that selects it, and
     * a removed partner gets tombstones, without touching any listing.
     *
     * @param list<int> $partnerIds the group's new full member list
     * @return array{hidden: int, revealed: int}
     */
    public function replaceGroupMembers(int $groupId, array $partnerIds): array
    {
        $partnerIds = array_values(array_unique(array_map('intval', $partnerIds)));
        $affected = [];

        foreach (array_unique(array_merge($this->groups->members($groupId), $partnerIds)) as $partnerId) {
            $partner = $this->partners->find((int) $partnerId);

            if ($partner !== null) {
                $affected[$partner->id] = $partner;
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $hidden = 0;
        $revealed = 0;

        // Snapshot each affected partner's visibility on each listing that
        // selects this group, apply the change, then diff — membership is
        // just another way for the answer to isVisibleTo() to change.
        $before = [];

        foreach ($this->groups->listingIdsSelectingGroup($groupId) as $listingId) {
            $listing = $this->listings->find($listingId);

            if ($listing === null || $listing->status === ListingStatus::Draft) {
                continue;
            }

            foreach ($affected as $partnerId => $partner) {
                $before[$listingId][$partnerId] = $this->isVisibleTo($listing, $partner);
            }
        }

        $this->groups->replaceMembers($groupId, $partnerIds);

        foreach ($before as $listingId => $partners) {
            $listing = $this->listings->find((int) $listingId);

            if ($listing === null) {
                continue;
            }

            foreach ($partners as $partnerId => $wasVisible) {
                $isVisible = $this->isVisibleTo($listing, $affected[$partnerId]);

                if ($wasVisible === $isVisible) {
                    continue;
                }

                $this->events->append(
                    (int) $listingId,
                    (int) $partnerId,
                    $isVisible ? VisibilityEventRepository::VISIBLE : VisibilityEventRepository::HIDDEN,
                    $now,
                );
                $isVisible ? $revealed++ : $hidden++;
            }
        }

        if ($hidden + $revealed > 0) {
            $this->logger->log(
                'sharing',
                "Group #{$groupId} membership changed: {$hidden} (listing, partner) pair(s) lose visibility, {$revealed} gain it",
                'group_membership_changed',
            );
        }

        return ['hidden' => $hidden, 'revealed' => $revealed];
    }

    /**
     * Change a partner's sharing scope, replaying the change through the
     * visibility event log exactly like an audience or membership change:
     * standard → curated tombstones every listing the partner saw only
     * via the everyone arm; curated → standard resurfaces them. Listings
     * the partner reaches via a pivot are visible either way and emit
     * nothing; drafts emit nothing (never distributed). The wire
     * timestamp never moves — the event log carries the transition.
     *
     * @return array{hidden: int, revealed: int}
     */
    public function setSharingScope(Partner $partner, SharingScope $scope): array
    {
        if ($partner->sharingScope === $scope) {
            return ['hidden' => 0, 'revealed' => 0];
        }

        $now = gmdate('Y-m-d H:i:s');
        $before = [];

        foreach ($this->listings->all() as $listing) {
            if ($listing->status === ListingStatus::Draft) {
                continue;
            }

            $before[$listing->id] = $this->isVisibleTo($listing, $partner);
        }

        $this->partners->update($partner->id, ['sharing_scope' => $scope]);
        $updated = $this->partners->find($partner->id) ?? $partner;
        $hidden = 0;
        $revealed = 0;

        foreach ($before as $listingId => $wasVisible) {
            $listing = $this->listings->find((int) $listingId);

            if ($listing === null) {
                continue;
            }

            $isVisible = $this->isVisibleTo($listing, $updated);

            if ($wasVisible === $isVisible) {
                continue;
            }

            $this->events->append(
                (int) $listingId,
                $partner->id,
                $isVisible ? VisibilityEventRepository::VISIBLE : VisibilityEventRepository::HIDDEN,
                $now,
            );
            $isVisible ? $revealed++ : $hidden++;
        }

        $this->logger->log(
            'sharing',
            "Sharing scope for {$partner->domain} set to {$scope->value}: {$hidden} listing(s) tombstone, {$revealed} resurface",
            'scope_changed',
            $partner->id,
        );

        return ['hidden' => $hidden, 'revealed' => $revealed];
    }

    /**
     * Replace the partner's full direct-share lineup for ONE listing type
     * (sale and charter are never mixed in one picker). Scoping the
     * replace to the type is what makes the per-type admin picker safe:
     * saving the sale list can never silently unshare charter listings
     * the form did not show. Group-derived shares are untouched — this
     * writes only the partner's own pivot rows.
     *
     * @param list<int> $listingIds the partner's new direct shares of this type
     * @return array{hidden: int, revealed: int}
     */
    public function replaceDirectShares(Partner $partner, string $type, array $listingIds): array
    {
        $listingIds = array_values(array_unique(array_map('intval', $listingIds)));
        $now = gmdate('Y-m-d H:i:s');
        $hidden = 0;
        $revealed = 0;

        foreach ($this->listings->all() as $listing) {
            if ($listing->type !== $type) {
                continue;
            }

            $current = $this->audience->partnersForListing($listing->id);
            $has = in_array($partner->id, $current, true);
            $shouldHave = in_array($listing->id, $listingIds, true);

            if ($has === $shouldHave) {
                continue;
            }

            $isDraft = $listing->status === ListingStatus::Draft;
            $before = $isDraft ? false : $this->isVisibleTo($listing, $partner);

            $this->audience->replaceForListing($listing->id, $shouldHave
                ? array_merge($current, [$partner->id])
                : array_values(array_diff($current, [$partner->id])));

            if ($isDraft) {
                continue;
            }

            $after = $this->isVisibleTo($listing, $partner);

            if ($before === $after) {
                continue;
            }

            $this->events->append(
                $listing->id,
                $partner->id,
                $after ? VisibilityEventRepository::VISIBLE : VisibilityEventRepository::HIDDEN,
                $now,
            );
            $after ? $revealed++ : $hidden++;
        }

        if ($hidden + $revealed > 0) {
            $this->logger->log(
                'sharing',
                "Direct {$type} shares for {$partner->domain} saved: {$hidden} listing(s) tombstone, {$revealed} resurface",
                'direct_shares_changed',
                $partner->id,
            );
        }

        return ['hidden' => $hidden, 'revealed' => $revealed];
    }

    /**
     * Delete a group safely: empty its membership first so the visibility
     * transitions land in the event log, then remove its rows.
     */
    public function deleteGroup(int $groupId): void
    {
        $this->replaceGroupMembers($groupId, []);
        $this->groups->delete($groupId);
        $this->logger->log('sharing', "Partner group #{$groupId} deleted", 'group_deleted');
    }
}

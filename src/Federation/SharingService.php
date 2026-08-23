<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

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
        $now = gmdate('Y-m-d H:i:s');
        $refreshed = 0;

        foreach ($this->listings->feedPage($partnerId, null, null, PHP_INT_MAX - 1) as $item) {
            $this->events->append($item->listing->id, $partnerId, VisibilityEventRepository::REFRESHED, $now);
            $refreshed++;
        }

        if ($refreshed > 0) {
            $this->logger->log('sharing', "Feed refreshed for partner #{$partnerId} ({$reason}): {$refreshed} listing(s) will resend", 'feed_refreshed');
        }

        return $refreshed;
    }

    public function isVisibleTo(Listing $listing, int $partnerId): bool
    {
        return match ($listing->audience) {
            Audience::Everyone => true,
            Audience::None => false,
            Audience::Selected => in_array($partnerId, $this->audience->partnersForListing($listing->id), true)
                || array_intersect($this->groups->groupIdsForListing($listing->id), $this->groups->groupIdsForPartner($partnerId)) !== [],
        };
    }

    /**
     * Change a listing's audience, recording a became-hidden /
     * became-visible transition for every partner whose view changed.
     * A selected audience is the union of individually selected partners
     * and the members of selected groups.
     *
     * @param list<int> $selectedPartnerIds partner ids for Audience::Selected
     * @param list<int> $selectedGroupIds group ids for Audience::Selected
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

        $visibleAfter = fn (int $partnerId): bool => match ($audience) {
            Audience::Everyone => true,
            Audience::None => false,
            Audience::Selected => in_array($partnerId, $selectedPartnerIds, true)
                || in_array($partnerId, $groupMemberIds, true),
        };

        foreach ($this->partners->all() as $partner) {
            // Drafts are never distributed (LS-7): audience changes before
            // first publication need no transitions — no partner ever saw
            // the listing, so there is nothing to tombstone or resurface.
            if ($listing->status === ListingStatus::Draft) {
                break;
            }

            $before = $this->isVisibleTo($listing, $partner->id);
            $after = $visibleAfter($partner->id);

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

        $this->audience->replaceForListing($listing->id, $audience === Audience::Selected ? $selectedPartnerIds : []);
        $this->groups->replaceForListing($listing->id, $audience === Audience::Selected ? $selectedGroupIds : []);
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
        $affected = array_values(array_unique(array_merge($this->groups->members($groupId), $partnerIds)));
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

            foreach ($affected as $partnerId) {
                $before[$listingId][$partnerId] = $this->isVisibleTo($listing, $partnerId);
            }
        }

        $this->groups->replaceMembers($groupId, $partnerIds);

        foreach ($before as $listingId => $partners) {
            $listing = $this->listings->find((int) $listingId);

            if ($listing === null) {
                continue;
            }

            foreach ($partners as $partnerId => $wasVisible) {
                $isVisible = $this->isVisibleTo($listing, (int) $partnerId);

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

<?php

/**
 * The public developer API. These functions — plus the openyacht_* actions
 * and the OpenYacht\Data read class — are the plugin's supported surface;
 * everything else is internal.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\Listing;
use OpenYacht\Federation\ListingStatus;
use OpenYacht\Services;

if (! function_exists('openyacht_publish_listing')) {
    /**
     * Publish a listing this site is the authority for, from wire-shaped
     * data (the listing-schema.md shape, minus the authority-minted id and
     * timestamps). Validated against the vendored JSON Schema and
     * registries before anything is served.
     *
     * @param array<string, mixed> $data
     * @return array{uuid: string, canonical_uri: string}|WP_Error
     */
    function openyacht_publish_listing(array $data)
    {
        $result = Services::ingest()->publish($data);

        if ($result instanceof WP_Error) {
            return $result;
        }

        return ['uuid' => $result->uuid, 'canonical_uri' => $result->canonicalUri()];
    }
}

if (! function_exists('openyacht_delist_listing')) {
    /**
     * End a listing (withdrawn or sold). Partners receive a tombstone in
     * their next updated_since poll (API-3).
     *
     * @return true|WP_Error
     */
    function openyacht_delist_listing(string $uuid, string $status = 'withdrawn')
    {
        $listing = Services::listings()->findByUuid($uuid);

        if ($listing === null) {
            return new WP_Error('openyacht_unknown_listing', "No listing with UUID [{$uuid}].");
        }

        $target = ListingStatus::tryFrom($status);

        if ($target === null || ! in_array($target, [ListingStatus::Withdrawn, ListingStatus::Sold], true)) {
            return new WP_Error('openyacht_invalid_status', 'Status must be withdrawn or sold.');
        }

        try {
            Services::listingService()->transition($listing, $target);
        } catch (InvalidArgumentException $exception) {
            return new WP_Error('openyacht_invalid_transition', $exception->getMessage());
        }

        return true;
    }
}

if (! function_exists('openyacht_get_listing')) {
    /**
     * Read one of this site's own listings by UUID.
     */
    function openyacht_get_listing(string $uuid): ?Listing
    {
        return Services::listings()->findByUuid($uuid);
    }
}

<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * The field groups an authority can grant or withhold per partner. The
 * payload a partner receives is the already-filtered view; withheld
 * values are nulled or emptied server-side (LS-14, API-5).
 *
 * // yacht-identity.md §Sharing Permissions and Usage Terms
 * // listing-schema.md §Field groups (gating map)
 */
enum FieldGroup: string
{
    case Pricing = 'pricing';
    case LocationExact = 'location_exact';
    case MediaOriginal = 'media_original';
    case Documents = 'documents';
    case VesselIdentifiers = 'vessel_identifiers';
    case History = 'history';
}

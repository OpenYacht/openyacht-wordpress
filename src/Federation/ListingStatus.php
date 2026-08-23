<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Listing lifecycle. Terminal states are terminal; draft is never
 * distributed (ID-8, LS-7).
 *
 * // yacht-identity.md §Lifecycle
 */
enum ListingStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case UnderOffer = 'under_offer';
    case Sold = 'sold';
    case Withdrawn = 'withdrawn';
}

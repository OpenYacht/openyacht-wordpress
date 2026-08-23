<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Serialises a sale listing to its wire payload for one partner.
 *
 * Every response contains the complete schema: a value the node does not
 * have — or that the partner's sharing rules withhold — is null or []
 * (LS-1). Filtering happens here, server-side, per the gating map
 * (LS-14, API-5): withheld values are nulled, never sent with
 * "please ignore" semantics. Money is strings with ISO 4217 codes
 * (API-12); drafts are never serialised (LS-7 — callers must not pass
 * them).
 *
 * // listing-schema.md
 */
final class ListingSerializer
{
    /** Flat specification keys, emitted null when unknown (LS-1, LS-2). */
    private const SPECIFICATION_KEYS = [
        'beam_m', 'draft_max_m', 'draft_min_m', 'lwl_m', 'lod_m',
        'bridge_clearance_m', 'gross_tonnage', 'displacement_kg',
        'fuel_capacity_l', 'water_capacity_l', 'holding_tank_l',
        'cruise_speed_kn', 'max_speed_kn', 'range_nmi', 'fuel_consumption_lph',
        'hull_material', 'superstructure_material', 'deck_material',
        'hull_shape', 'hull_color', 'naval_architect', 'exterior_designer',
        'interior_designer', 'fuel_type', 'flag', 'registry_port',
        'power_or_sail', 'category',
        'cabins', 'sleeps', 'heads', 'guests_cruising', 'guests_entertaining',
        'cabin_config', 'berth_config', 'crew_accommodation',
        'engines', 'generators', 'tenders',
    ];

    public function __construct(
        private readonly PriceHistoryRepository $prices,
        private readonly ListingMediaRepository $media,
    ) {
    }

    /**
     * Serialise for one partner — or, with a null partner, ungated (the
     * full internal view, used for input-time validation; never served on
     * the federation surface to an actual partner without gating).
     *
     * @return array<string, mixed>
     */
    public function serialize(Listing $listing, ?Partner $partner): array
    {
        $granted = static fn (FieldGroup $group): bool => $partner === null || $partner->hasFieldGroup($group);

        return [
            'id' => $listing->canonicalUri(),
            'type' => 'sale',
            'status' => $listing->status->value,
            'updated_at' => self::rfc3339($listing->federationUpdatedAt),
            'listed_at' => self::rfc3339($listing->listedAt),
            'condition' => $listing->condition,
            'agreement' => ['type' => null, 'co_brokerage' => null],
            'vessel' => $this->vessel($listing, $granted(FieldGroup::VesselIdentifiers)),
            'listing' => $this->listing($listing, $granted(FieldGroup::Pricing), $granted(FieldGroup::LocationExact), $granted(FieldGroup::History)),
            'specifications' => $this->specifications($listing),
            'descriptions' => array_values($listing->descriptions),
            'features' => array_values($listing->features),
            'media' => $this->mediaBlock($listing, $granted(FieldGroup::Documents)),
            'charter' => null,
            'usage' => $this->usage(),
            'compliance' => $this->compliance($listing),
        ];
    }

    /**
     * The tombstone form: served in updated_since results for every
     * listing that became invisible to the requesting partner (API-3).
     *
     * @return array<string, mixed>
     */
    public function tombstone(Listing $listing): array
    {
        return [
            'id' => $listing->canonicalUri(),
            'tombstone' => true,
            'status' => $listing->status->value,
            'updated_at' => self::rfc3339($listing->federationUpdatedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function vessel(Listing $listing, bool $identifiersGranted): array
    {
        return [
            'hin' => $identifiersGranted ? $listing->hin : null,
            'imo' => $identifiersGranted ? $listing->imo : null,
            'mmsi' => $identifiersGranted ? $listing->mmsi : null,
            'official_number' => $identifiersGranted ? $listing->officialNumber : null,
            'builder' => ['name' => $listing->builderName, 'slug' => $listing->builderSlug],
            'model' => ['name' => $listing->modelName, 'slug' => null],
            'year_built' => $listing->yearBuilt,
            'refit_year' => $listing->refitYear,
            'loa_m' => $listing->loaM,
            'previous_names' => $listing->previousNames,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function listing(Listing $listing, bool $pricingGranted, bool $locationGranted, bool $historyGranted): array
    {
        $priceShared = $pricingGranted && ! $listing->priceOnApplication;

        return [
            'name' => $listing->name,
            'summary' => $listing->summary,
            'price' => [
                'amount' => $priceShared ? $listing->priceAmount : null,
                'currency' => $priceShared ? $listing->priceCurrency : null,
                'on_application' => $listing->priceOnApplication,
                'starting_price' => $listing->startingPrice,
            ],
            'price_history' => $historyGranted && $pricingGranted
                ? array_map(
                    static fn (array $entry): array => [
                        'amount' => $entry['amount'],
                        'currency' => $entry['currency'],
                        'changed_at' => self::rfc3339($entry['changed_at']),
                    ],
                    $this->prices->forListing($listing->id),
                )
                : [],
            'location' => [
                'display' => $listing->locationDisplay,
                'city' => $listing->locationCity,
                'state' => $listing->locationState,
                'country' => $listing->locationCountry,
                'marina' => $locationGranted ? $listing->locationMarina : null,
                'coordinates' => $locationGranted && $listing->locationLat !== null && $listing->locationLon !== null
                    ? ['lat' => $listing->locationLat, 'lon' => $listing->locationLon]
                    : null,
            ],
            'brokers' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function specifications(Listing $listing): array
    {
        $stored = $listing->specifications;
        $complete = [];

        foreach (self::SPECIFICATION_KEYS as $key) {
            $complete[$key] = $stored[$key] ?? match ($key) {
                'cabin_config' => ['double' => null, 'twin' => null, 'triple' => null, 'single' => null, 'convertible' => null],
                'berth_config' => ['king' => null, 'queen' => null, 'double' => null, 'twin' => null, 'single' => null, 'pullman' => null, 'bunk' => null],
                'crew_accommodation' => ['cabins' => null, 'berths' => null, 'layout' => null],
                'engines', 'generators' => [],
                default => null,
            };
        }

        return $complete;
    }

    /**
     * Media with content hashes; the profile hero and its thumbnail are
     * mandatory whenever imagery exists, and a listing with no imagery has
     * profile: null — never a placeholder (LS-8).
     *
     * @return array<string, mixed>
     */
    private function mediaBlock(Listing $listing, bool $documentsGranted): array
    {
        $profile = null;
        $gallery = [];
        $sort = 0;

        foreach ($this->media->forListing($listing->id) as $item) {
            if ($item->kind === 'profile' && $profile === null) {
                $profile = [
                    'url' => $item->url,
                    'sha256' => $item->sha256,
                    'width' => $item->width,
                    'height' => $item->height,
                    'caption' => $item->caption,
                    'thumbnail_url' => $item->thumbnailUrl,
                ];
            } elseif ($item->kind === 'gallery') {
                $gallery[] = [
                    'url' => $item->url,
                    'sha256' => $item->sha256,
                    'category' => $item->category,
                    'width' => $item->width,
                    'height' => $item->height,
                    'caption' => $item->caption,
                    'sort' => ++$sort,
                ];
            }
        }

        return [
            'profile' => $profile,
            'gallery' => $gallery,
            'layouts' => [],
            'videos' => [],
            'tours' => [],
            'documents' => $documentsGranted ? [] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function usage(): array
    {
        return [
            'display' => true,
            'attribution_required' => true,
            'attribution_text' => sprintf(
                /* translators: %s: this node's name. */
                __('Listing courtesy of %s', 'openyacht'),
                NodeConfig::nodeName(),
            ),
            'marketing_materials' => true,
            'ai_indexing' => true,
            'expires_with_listing' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function compliance(Listing $listing): array
    {
        $stored = $listing->compliance;

        return [
            'not_for_sale_to_us_residents_in_us_waters' => $stored['not_for_sale_to_us_residents_in_us_waters'] ?? null,
            'vat_status' => $stored['vat_status'] ?? null,
            'ce_certified' => $stored['ce_certified'] ?? null,
            'mca_compliant' => $stored['mca_compliant'] ?? null,
            'classification' => $stored['classification'] ?? [],
        ];
    }

    private static function rfc3339(?string $stored): ?string
    {
        if ($stored === null) {
            return null;
        }

        $timestamp = strtotime($stored . ' UTC');

        return $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}

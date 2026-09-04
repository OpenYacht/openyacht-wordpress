<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * A sale listing this node is the authority for.
 *
 * The canonical UUID is minted once at creation and never reused; the
 * canonical URI never changes for the life of the listing (ID-1). Price
 * changes append to the price history, never rewrite it (LS-10). Status
 * follows the lifecycle draft → active ⇄ under_offer → sold | withdrawn,
 * and the terminal states are final — a returning vessel gets a new
 * listing with a new UUID (ID-8).
 *
 * // yacht-identity.md §Listing Identity, §Lifecycle
 */
final class Listing
{
    /**
     * @param list<string> $previousNames
     * @param array<string, mixed> $specifications
     * @param list<array<string, mixed>> $descriptions
     * @param list<array<string, mixed>> $features
     * @param array<string, mixed> $compliance
     */
    public function __construct(
        public readonly int $id,
        public readonly string $uuid,
        public readonly string $type,
        public readonly ListingStatus $status,
        public readonly ?string $name,
        public readonly ?string $summary,
        public readonly ?string $condition,
        public readonly ?string $hin,
        public readonly ?string $imo,
        public readonly ?string $mmsi,
        public readonly ?string $officialNumber,
        public readonly ?string $builderName,
        public readonly ?string $builderSlug,
        public readonly ?string $modelName,
        public readonly ?int $yearBuilt,
        public readonly ?int $refitYear,
        public readonly ?float $loaM,
        public readonly array $previousNames,
        public readonly ?string $priceAmount,
        public readonly ?string $priceCurrency,
        public readonly bool $priceOnApplication,
        public readonly bool $startingPrice,
        public readonly ?string $locationDisplay,
        public readonly ?string $locationCity,
        public readonly ?string $locationState,
        public readonly ?string $locationCountry,
        public readonly ?string $locationMarina,
        public readonly ?float $locationLat,
        public readonly ?float $locationLon,
        public readonly array $specifications,
        public readonly array $descriptions,
        public readonly array $features,
        public readonly array $compliance,
        public readonly ?string $listedAt,
        public readonly ?string $federationUpdatedAt,
        public readonly Audience $audience = Audience::Everyone,
    ) {
    }

    /**
     * The listing's canonical URI — its globally unique identifier,
     * immutable for the life of the listing (ID-1).
     */
    public function canonicalUri(): string
    {
        return 'https://' . NodeConfig::identityDomain() . "/openyacht/v1/listings/{$this->uuid}";
    }

    /**
     * The transitions the lifecycle allows from the current status (ID-8).
     *
     * @return list<ListingStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this->status) {
            ListingStatus::Draft => [ListingStatus::Active],
            ListingStatus::Active => [ListingStatus::UnderOffer, ListingStatus::Sold, ListingStatus::Withdrawn],
            ListingStatus::UnderOffer => [ListingStatus::Active, ListingStatus::Sold, ListingStatus::Withdrawn],
            ListingStatus::Sold, ListingStatus::Withdrawn => [],
        };
    }

    public function canTransitionTo(ListingStatus $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->status === ListingStatus::Sold || $this->status === ListingStatus::Withdrawn;
    }
}

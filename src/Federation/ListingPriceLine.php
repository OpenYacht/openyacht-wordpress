<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * One price line for any wire listing, either type. Sale listings show
 * the asking price; charter listings have no asking price by design —
 * their pricing is the charter rate block, so the line falls back to the
 * rate range. The range sticks to one currency (the first rate's: mixed
 * summer/winter currencies are real and must not be blended into one
 * mislabelled span) and one rate type, so a weekly range is never
 * labelled from daily rates.
 *
 * Shared by the synced-listings screens; the same rule as the reference
 * app's formatter so both nodes describe a listing the same way.
 *
 * // listing-schema.md §Charter (rates[].rate_type, currency)
 */
final class ListingPriceLine
{
    /**
     * @param array<string, mixed> $payload the verbatim wire listing
     */
    public static function fromPayload(array $payload): ?string
    {
        $price = is_array($payload['listing']['price'] ?? null) ? $payload['listing']['price'] : [];

        if (! empty($price['on_application'])) {
            return __('POA', 'openyacht');
        }

        if (is_numeric($price['amount'] ?? null) && is_string($price['currency'] ?? null) && $price['currency'] !== '') {
            return $price['currency'] . ' ' . number_format((float) $price['amount']);
        }

        $rates = $payload['charter']['rates'] ?? null;

        return is_array($rates) ? self::rateRange($rates) : null;
    }

    /**
     * @param list<mixed> $rates the wire charter.rates block
     */
    public static function rateRange(array $rates): ?string
    {
        $rates = array_values(array_filter($rates, 'is_array'));
        $currency = null;

        foreach ($rates as $rate) {
            if (is_string($rate['currency'] ?? null) && $rate['currency'] !== '') {
                $currency = $rate['currency'];
                break;
            }
        }

        if ($currency === null) {
            return null;
        }

        $comparable = array_values(array_filter(
            $rates,
            static fn (array $rate): bool => ($rate['currency'] ?? null) === $currency,
        ));
        // An unstated rate type is weekly — the spec's default reading.
        $rateType = ($comparable[0]['rate_type'] ?? 'weekly') === 'daily' ? 'daily' : 'weekly';
        $scoped = array_filter(
            $comparable,
            static fn (array $rate): bool => ($rate['rate_type'] ?? 'weekly') === $rateType,
        );

        $positive = static fn (string $key): array => array_values(array_filter(array_map(
            static fn (array $rate): float => is_numeric($rate[$key] ?? null) ? (float) $rate[$key] : 0.0,
            $scoped,
        ), static fn (float $value): bool => $value > 0));
        $mins = $positive('amount_min');
        $maxs = $positive('amount_max');

        if ($mins === [] && $maxs === []) {
            return null;
        }

        $low = min($mins !== [] ? $mins : $maxs);
        $high = max($maxs !== [] ? $maxs : $mins);
        $range = $high > $low ? number_format($low) . '–' . number_format($high) : number_format($low);
        $suffix = $rateType === 'daily' ? __('/ day', 'openyacht') : __('/ week', 'openyacht');

        return $currency . ' ' . $range . ' ' . $suffix;
    }
}

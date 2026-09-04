<?php

declare(strict_types=1);

namespace OpenYacht\Cli;

if (! defined('ABSPATH')) {
    exit;
}

use OpenYacht\Federation\BuilderRegistry;
use OpenYacht\Services;
use WP_CLI;
use WP_Error;

/**
 * Clearly fictional test inventory: registry builders, invented vessels —
 * never real-boat data. `wp openyacht seed`.
 */
final class SeedCommand
{
    private const VESSEL_NAMES = [
        'FICTIONAL DAWN', 'TEST PATTERN', 'NOT FOR SALE REALLY', 'LOREM IPSEA',
        'PLACEHOLDER QUEEN', 'SAMPLE SIZE', 'DRY RUN', 'MOCK TURTLE',
    ];

    /**
     * Create fictional active listings for federation testing.
     *
     * ## OPTIONS
     *
     * [--count=<n>]
     * : How many listings to create (default 5, max 8).
     *
     * ## EXAMPLES
     *
     *     wp openyacht seed --count=5
     *
     * @param array<int, string> $args
     * @param array<string, string> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $count = min(max((int) ($assocArgs['count'] ?? 5), 1), count(self::VESSEL_NAMES));
        $builders = (new BuilderRegistry())->all();

        for ($i = 0; $i < $count; $i++) {
            $builder = $builders[($i * 37) % count($builders)];
            $name = self::VESSEL_NAMES[$i];

            $result = Services::ingest()->publish([
                'status' => 'active',
                'condition' => $i % 2 === 0 ? 'used' : 'new',
                'vessel' => [
                    'builder' => ['name' => $builder['name'], 'slug' => $builder['slug']],
                    'model' => ['name' => 'Imaginary ' . (30 + $i * 4), 'slug' => null],
                    'year_built' => 2015 + $i,
                    'loa_m' => 30.5 + $i * 4.2,
                    'previous_names' => $i % 3 === 0 ? ['EX FICTIONAL'] : [],
                ],
                'listing' => [
                    'name' => $name,
                    'summary' => 'A clearly fictional test listing seeded by the OpenYacht WordPress plugin. Not a real vessel.',
                    'price' => [
                        'amount' => (string) ((2_000_000 + $i * 750_000)),
                        'currency' => $i % 2 === 0 ? 'EUR' : 'USD',
                        'on_application' => $i === 3,
                        'starting_price' => false,
                    ],
                    'location' => [
                        'display' => 'Testville, Fictionia',
                        'city' => 'Testville',
                        'country' => 'FR',
                        'marina' => 'Marina Imaginaria',
                        'coordinates' => ['lat' => 43.2 + $i / 10, 'lon' => 6.6 + $i / 10],
                    ],
                ],
                'specifications' => [
                    'power_or_sail' => $i % 4 === 0 ? 'sail' : 'power',
                    'beam_m' => 7.1 + $i / 2,
                    'draft_max_m' => 2.2 + $i / 4,
                    'cabins' => 4 + $i % 3,
                    'sleeps' => 8 + ($i % 3) * 2,
                    'guests_cruising' => 12,
                    'category' => ['name' => 'Motor Yacht', 'slug' => 'motor-yacht'],
                ],
                'descriptions' => [[
                    'section' => 'overview',
                    'content' => '<p>A <strong>fictional</strong> vessel seeded for federation testing. Any resemblance to real yachts is coincidental.</p>',
                ]],
                'features' => [
                    ['category' => 'entertainment', 'name' => 'Imaginary cinema', 'slug' => null],
                ],
                'compliance' => ['vat_status' => 'paid'],
            ]);

            if ($result instanceof WP_Error) {
                WP_CLI::warning("{$name}: " . $result->get_error_message() . ' ' . implode(' | ', (array) $result->get_error_data()));

                continue;
            }

            WP_CLI::line("{$name}: {$result->canonicalUri()}");
        }

        WP_CLI::success('Seeding complete.');
    }
}

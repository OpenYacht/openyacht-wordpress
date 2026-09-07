<?php

declare(strict_types=1);

namespace OpenYacht\Tests\Unit\Federation;

use Brain\Monkey\Functions;
use OpenYacht\Federation\ListingPriceLine;
use OpenYacht\Tests\Unit\TestCase;

final class ListingPriceLineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Functions\stubTranslationFunctions();
    }

    /**
     * @param list<array<string, mixed>> $rates
     * @return array<string, mixed>
     */
    private function charter(array $rates): array
    {
        return ['type' => 'charter', 'listing' => ['price' => null], 'charter' => ['rates' => $rates]];
    }

    public function testSalePriceAndPoaComeFirst(): void
    {
        self::assertSame('EUR 8,500,000', ListingPriceLine::fromPayload(['listing' => ['price' => ['amount' => '8500000', 'currency' => 'EUR']]]));
        self::assertSame('POA', ListingPriceLine::fromPayload(['listing' => ['price' => ['amount' => null, 'currency' => null, 'on_application' => true]]]));
        self::assertNull(ListingPriceLine::fromPayload(['listing' => ['price' => ['amount' => null, 'currency' => null]]]), 'withheld pricing has no line');
        self::assertNull(ListingPriceLine::fromPayload($this->charter([])));
    }

    public function testMixedCurrencyRateSetsStickToTheFirstCurrency(): void
    {
        // EUR summer + USD winter is a real charter rate card; blending
        // them into one span would mislabel the range.
        $line = ListingPriceLine::fromPayload($this->charter([
            ['season' => 'summer', 'rate_type' => 'weekly', 'amount_min' => '100000', 'amount_max' => '120000', 'currency' => 'EUR'],
            ['season' => 'winter', 'rate_type' => 'weekly', 'amount_min' => '90000', 'amount_max' => '95000', 'currency' => 'USD'],
        ]));

        self::assertSame('EUR 100,000–120,000 / week', $line);
    }

    public function testDailyRatesAreLabelledDailyAndNeverMixedWithWeekly(): void
    {
        self::assertSame('USD 15,000–18,000 / day', ListingPriceLine::fromPayload($this->charter([
            ['season' => 'summer', 'rate_type' => 'daily', 'amount_min' => '15000', 'amount_max' => '18000', 'currency' => 'USD'],
            ['season' => 'winter', 'rate_type' => 'weekly', 'amount_min' => '80000', 'amount_max' => '90000', 'currency' => 'USD'],
        ])));

        self::assertSame('EUR 50,000 / week', ListingPriceLine::fromPayload($this->charter([
            ['season' => 'summer', 'rate_type' => null, 'amount_min' => '50000', 'amount_max' => '50000', 'currency' => 'EUR'],
        ])), 'a fixed rate shows once; an unstated rate type reads as weekly');
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Configurator;

use Core\Configurator\Assembly\Measure;
use Core\Configurator\Bom\BomLine;
use Core\Configurator\Pricing\Quote;
use Core\Configurator\Pricing\QuoteLine;
use Core\Configurator\Product\SpongeCity\Funding\Applicant;
use Core\Configurator\Product\SpongeCity\Funding\FundingEstimate;
use Core\Configurator\Product\SpongeCity\Funding\FundingProgramme;
use Core\Configurator\Product\SpongeCity\Funding\HamburgFunding;
use Core\Configurator\Product\SpongeCity\SpongeCitySku as Sku;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the programmes pay towards a quote: their share of the eligible lines, per applicant, capped.
 */
final class FundingEstimateTest extends TestCase
{
    #[Test]
    public function paysTheApplicantsShareOfTheEligibleLinesOnly(): void
    {
        // 100 m² of green roof at 95 € = 9,500 €, its set-up 8,000 € (not eligible), 50 m² of planting 1,750 €
        $quote = self::quote([[Sku::GREENROOF_EXTENSIVE, 100.0, 9500_00], [Sku::GREENROOF_SETUP, 1.0, 8000_00], [Sku::PLANTING, 50.0, 1750_00]]);

        $estimate = FundingEstimate::of($quote, Applicant::MediumCompany, HamburgFunding::programmes());

        $lines = array_column(array_map(static fn (array $l): array => [...$l, 'key' => $l['programme']->key], $estimate->lines), null, 'key');
        self::assertSame(9500_00, $lines[HamburgFunding::GREEN_ROOF]['eligible']);
        self::assertSame(4750_00, $lines[HamburgFunding::GREEN_ROOF]['amount'], 'a medium company gets 50 %');
        self::assertSame(525_00, $lines[HamburgFunding::UNSEALING]['amount'], 'the lower end, 30 %');
        self::assertSame(5275_00, $estimate->total());
    }

    #[Test]
    public function anUnknownApplicantGetsTheHighestShareAndAPublicOwnerNothing(): void
    {
        $quote = self::quote([[Sku::GREENROOF_EXTENSIVE, 100.0, 10000_00]]);

        self::assertSame(6000_00, FundingEstimate::of($quote, Applicant::Unknown, HamburgFunding::programmes())->total());
        self::assertSame(0, FundingEstimate::of($quote, Applicant::Public, HamburgFunding::programmes())->total());
    }

    #[Test]
    public function capsPerBuildingAndFundsNothingBelowTheMinimum(): void
    {
        $programme = new FundingProgramme('p', 'P', 'X', 'https://example.org', '2026', null, [Sku::GREENROOF_EXTENSIVE], [Applicant::Private->value => 0.5], capCents: 1000_00, capPerSku: Sku::GREENROOF_SETUP, minEligibleAmount: 20.0);

        $twoBuildings = self::quote([[Sku::GREENROOF_EXTENSIVE, 500.0, 50000_00], [Sku::GREENROOF_SETUP, 2.0, 16000_00]]);
        $line = FundingEstimate::of($twoBuildings, Applicant::Private, [$programme])->lines[0];
        self::assertSame(2000_00, $line['amount'], 'the cap counts once per building');
        self::assertTrue($line['capped']);

        $tiny = FundingEstimate::of(self::quote([[Sku::GREENROOF_EXTENSIVE, 10.0, 1000_00]]), Applicant::Private, [$programme])->lines[0];
        self::assertSame(0, $tiny['amount']);
        self::assertTrue($tiny['below_minimum']);
    }

    /**
     * @param list<array{0: string, 1: float, 2: int}> $lines sku, amount (m² or pieces), total cents
     */
    private static function quote(array $lines): Quote
    {
        return new Quote(array_map(static function (array $l): QuoteLine {
            $measure = Sku::GREENROOF_SETUP === $l[0] ? Measure::piece() : Measure::area($l[1] * 1_000_000, 1.0);
            $quantity = Sku::GREENROOF_SETUP === $l[0] ? (int) $l[1] : 1;

            return new QuoteLine(new BomLine($l[0], $l[0], $measure, $quantity), null, $l[2]);
        }, $lines), 'EUR');
    }
}

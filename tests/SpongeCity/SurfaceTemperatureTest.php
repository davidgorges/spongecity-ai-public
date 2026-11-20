<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity;

use Core\Configurator\Product\SpongeCity\Impact\CoolingCalculator;
use Core\Configurator\Product\SpongeCity\Impact\GroundClass;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The surface temperature reads what is known about the ground, and no more: ground nobody described
 * counts halfway between asphalt and lawn (and the report says how far it could go), shade cools a
 * surface the more the hotter it is in the sun, and dark asphalt is what it is on a hot north German day.
 */
final class SurfaceTemperatureTest extends TestCase
{
    private const int STORM = 40;
    /** 30 m × 30 m */
    private const array PLOT = [[-15000, -15000], [15000, -15000], [15000, 15000], [-15000, 15000]];

    #[Test]
    public function groundNobodyDescribedIsNotTakenForAsphalt(): void
    {
        $unknown = (new ImpactCalculator())->calculate($this->site(O::SURFACE_UNKNOWN), self::STORM);
        $asphalt = (new ImpactCalculator())->calculate($this->site(O::SURFACE_ASPHALT), self::STORM);

        $halfway = (GroundClass::Asphalt->surfaceTemperature() + GroundClass::Lawn->surfaceTemperature()) / 2;
        self::assertEqualsWithDelta($halfway, (float) $unknown->metric(ImpactCalculator::METRIC_SURFACE_TEMPERATURE)?->baseline, 0.1);
        self::assertEqualsWithDelta(GroundClass::Asphalt->surfaceTemperature(), (float) $asphalt->metric(ImpactCalculator::METRIC_SURFACE_TEMPERATURE)?->baseline, 0.1, 'known asphalt stays asphalt');
        self::assertLessThan((float) $asphalt->metric(ImpactCalculator::METRIC_PEAK_COEFFICIENT)?->baseline, (float) $unknown->metric(ImpactCalculator::METRIC_PEAK_COEFFICIENT)?->baseline, 'half sealed sheds less rain than asphalt');
        self::assertSame(0.0, (float) $unknown->metric(ImpactCalculator::METRIC_PERMEABLE_SHARE)?->baseline, 'nobody knows it lets water through');
        self::assertArrayHasKey(GroundClass::Unknown->value, $unknown->areasSquareMetres);
    }

    #[Test]
    public function theReportSaysHowFarUnknownGroundCouldGoEitherWay(): void
    {
        $report = (new ImpactCalculator())->calculate($this->site(O::SURFACE_UNKNOWN), self::STORM);

        $warning = implode("\n", array_filter($report->warnings, static fn (string $w): bool => str_contains($w, 'unknown surface')));
        self::assertStringContainsString('900 m² of the open ground (100 %)', $warning);
        self::assertStringContainsString(sprintf('all asphalt %.1f °C', GroundClass::Asphalt->surfaceTemperature()), $warning);
        self::assertStringContainsString(sprintf('all lawn %.1f °C', GroundClass::Lawn->surfaceTemperature()), $warning);
        self::assertStringContainsString('describe_zone', $warning, 'tells the agent how to narrow it');

        $known = (new ImpactCalculator())->calculate($this->site(O::SURFACE_ASPHALT), self::STORM);
        self::assertSame([], array_filter($known->warnings, static fn (string $w): bool => str_contains($w, 'unknown surface')), 'no doubt where the ground is known');
    }

    #[Test]
    public function aZoneOnTheMapIsKnownGroundOnAnUnknownPlot(): void
    {
        // the west half is a lawn on the map, the rest nobody described
        $lawn = [O::TYPE => O::AREA, O::USE => O::AREA_GREEN, O::SURFACE => O::SURFACE_LAWN, O::POINTS => [[-15000, -15000], [0, -15000], [0, 15000], [-15000, 15000]]];

        $report = (new ImpactCalculator())->calculate($this->site(O::SURFACE_UNKNOWN, [$lawn]), self::STORM);

        $expected = (GroundClass::Lawn->surfaceTemperature() + GroundClass::Unknown->surfaceTemperature()) / 2;
        self::assertEqualsWithDelta($expected, (float) $report->metric(ImpactCalculator::METRIC_SURFACE_TEMPERATURE)?->baseline, 0.3);
        self::assertEqualsWithDelta(50.0, (float) $report->metric(ImpactCalculator::METRIC_PERMEABLE_SHARE)?->baseline, 1.0, 'the lawn is known to be permeable, the rest is not known to be');
    }

    #[Test]
    public function unknownGroundDrainsIntoARainGardenAsFarAsItMayBeSealed(): void
    {
        // a 6 m² rain garden takes at most 10 × 6 m²: more than the sealed share of 894 m² of unknown ground is far beyond that
        $garden = [O::TYPE => O::RAINGARDEN, O::POINTS => [[0, 0], [3000, 0], [3000, 2000], [0, 2000]]];

        $unknown = (new ImpactCalculator())->calculate($this->site(O::SURFACE_UNKNOWN, [$garden]), self::STORM);

        $warning = implode("\n", array_filter($unknown->warnings, static fn (string $w): bool => str_contains($w, 'small for the')));
        self::assertMatchesRegularExpression('/small for the (\d+) m² of sealed surface/', $warning);
        preg_match('/small for the (\d+) m²/', $warning, $match);
        self::assertEqualsWithDelta(894 * GroundClass::Unknown->sealedShare(), (float) $match[1], 5.0, 'half of the unknown ground drains like sealed ground');
    }

    #[Test]
    public function shadeCoolsAHotSurfaceMoreThanACoolOne(): void
    {
        $air = CoolingCalculator::REFERENCE_AIR_TEMPERATURE;
        $asphaltCooling = GroundClass::Asphalt->surfaceTemperature() - GroundClass::Asphalt->surfaceTemperatureIn(true);
        $lawnCooling = GroundClass::Lawn->surfaceTemperature() - GroundClass::Lawn->surfaceTemperatureIn(true);

        self::assertEqualsWithDelta((GroundClass::Asphalt->surfaceTemperature() - $air) * (1 - GroundClass::SHADED_EXCESS_SHARE), $asphaltCooling, 0.001);
        self::assertGreaterThanOrEqual(11.0, $asphaltCooling, 'US EPA: shaded surfaces 11 to 25 °C cooler than sunlit materials');
        self::assertLessThanOrEqual(25.0, $asphaltCooling);
        self::assertLessThan(2.0, $lawnCooling, 'lawn is close to the air already');
        self::assertSame(GroundClass::Water->surfaceTemperature(), GroundClass::Water->surfaceTemperatureIn(true), 'shade never warms what is cooler than the air');
        foreach (GroundClass::cases() as $class) {
            self::assertSame($class->surfaceTemperature(), $class->surfaceTemperatureIn(false));
            self::assertLessThanOrEqual($class->surfaceTemperature(), $class->surfaceTemperatureIn(true), $class->value);
        }
    }

    #[Test]
    public function aTreeOverAsphaltCoolsTheGroundMoreThanATreeOverLawn(): void
    {
        $tree = [O::TYPE => O::NEWTREE, O::X => 0, O::Z => 0, O::HEIGHT => 14000];

        $overAsphalt = (new ImpactCalculator())->calculate($this->site(O::SURFACE_ASPHALT, [$tree]), self::STORM)->metric(ImpactCalculator::METRIC_SURFACE_TEMPERATURE);
        $overLawn = (new ImpactCalculator())->calculate($this->site(O::SURFACE_LAWN, [$tree]), self::STORM)->metric(ImpactCalculator::METRIC_SURFACE_TEMPERATURE);

        self::assertLessThan(0.0, (float) $overAsphalt?->delta());
        self::assertLessThan((float) $overLawn?->delta(), (float) $overAsphalt?->delta(), 'a crown over hot asphalt takes more heat off the ground');
    }

    #[Test]
    public function asphaltIsAHotNorthGermanDayAndACoolPavementFollowsIt(): void
    {
        self::assertEqualsWithDelta(55.0, GroundClass::Asphalt->surfaceTemperature(), 0.001, '50 to 55 °C on a hot day, not a southern heat-wave peak');
        self::assertSame(GroundClass::Asphalt->surfaceTemperature() - GroundClass::COOL_PAVEMENT_REDUCTION, GroundClass::CoolPavement->surfaceTemperature());
    }

    #[Test]
    public function unknownGroundLiesHalfwayInEveryNumber(): void
    {
        [$a, $b] = GroundClass::UNKNOWN_BOUNDS;
        $half = static fn (callable $of): float => ($of($a) + $of($b)) / 2;

        self::assertEqualsWithDelta($half(static fn (GroundClass $c): float => $c->runoffCoefficient()), GroundClass::Unknown->runoffCoefficient(), 0.0001);
        self::assertEqualsWithDelta($half(static fn (GroundClass $c): float => $c->cropCoefficient()), GroundClass::Unknown->cropCoefficient(), 0.0001);
        self::assertEqualsWithDelta(0.5, GroundClass::Unknown->sealedShare(), 0.0001);
        self::assertFalse(GroundClass::Unknown->isSealed());
        self::assertFalse(GroundClass::Unknown->isKnown());
        self::assertEqualsWithDelta(1.0, array_sum(GroundClass::Unknown->yearlyWaterBalance()), 0.0001);
        self::assertSame(GroundClass::Unknown, GroundClass::fromSurface(O::SURFACE_UNKNOWN));
    }

    /**
     * @param list<array<string, mixed>> $objects
     */
    private function site(string $plotSurface, array $objects = []): Site
    {
        return Site::fromJson((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => $plotSurface],
            ...$objects,
        ]]));
    }
}

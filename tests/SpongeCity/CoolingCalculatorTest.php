<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity;

use Core\Configurator\Product\SpongeCity\Impact\CoolingCalculator;
use Core\Configurator\Product\SpongeCity\Impact\CoolingScenario as K;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Cooling and water of a plot: what evaporates on a hot day, how warm the ground and the people
 * get, where a year's rain goes, and what each kind of measure adds to it.
 */
final class CoolingCalculatorTest extends TestCase
{
    /** a 30 x 30 m plot */
    private const array PLOT = [[-15000, -15000], [15000, -15000], [15000, 15000], [-15000, 15000]];

    #[Test]
    public function aSunnyLawnEvaporatesEtZeroTimesItsCropCoefficient(): void
    {
        $report = $this->calculator()->calculate($this->site([], O::SURFACE_LAWN));
        self::assertNotNull($report);

        // 900 m² x 5 mm x 0.8 = 3600 litres, 0.68 kWh each
        self::assertEqualsWithDelta(3600.0, $report->design->value(K::KEY_EVAPOTRANSPIRATION), 1.0);
        self::assertEqualsWithDelta(3600.0 * 2.45 / 3.6, $report->design->value(K::KEY_LATENT_COOLING), 1.0);
        self::assertEqualsWithDelta(31.0, $report->design->value(K::KEY_SURFACE_TEMPERATURE), 0.01);
        // lawn: 3 % runoff, 40 % infiltration, 57 % evaporation of a year's rain
        self::assertEqualsWithDelta(3.0, $report->design->value(K::KEY_RUNOFF_SHARE), 0.01);
        self::assertEqualsWithDelta(900 * 0.79 * 0.57, $report->design->value(K::KEY_EVAPORATION), 0.5);
        // full sun on green ground: PET 40 °C, strong heat stress, not extreme
        self::assertEqualsWithDelta(40.0, $report->design->value(K::KEY_PET), 0.01);
        self::assertEqualsWithDelta(100.0, $report->design->value(K::KEY_STRONG_HEAT), 0.01);
        self::assertSame([], $report->without, 'nothing planned, nothing to credit');
    }

    #[Test]
    public function sealedGroundDoesNotEvaporateAndEveryoneSweltersInTheSun(): void
    {
        $report = $this->calculator()->calculate($this->site([]));
        self::assertNotNull($report);

        self::assertSame(0.0, $report->design->value(K::KEY_EVAPOTRANSPIRATION));
        self::assertEqualsWithDelta(80.0, $report->design->value(K::KEY_RUNOFF_SHARE), 0.01);
        self::assertEqualsWithDelta(100.0, $report->design->value(K::KEY_EXTREME_HEAT), 0.01);
        self::assertEquals($report->baseline->values, $report->design->values);
    }

    #[Test]
    public function aTreeIsCreditedWithItsShadeTranspirationAndInterception(): void
    {
        $report = $this->calculator()->calculate($this->site([[O::TYPE => O::NEWTREE, O::X => 0, O::Z => 0, O::HEIGHT => 12000]]));
        self::assertNotNull($report);
        $tree = $report->contributionOf(O::NEWTREE);

        // crown radius 28 % of 12 m: 3.36 m, 35.5 m² of crown x 5 mm x 0.7
        self::assertEqualsWithDelta(M_PI * 3.36 ** 2 * 5 * 0.7, $tree[K::KEY_EVAPOTRANSPIRATION_TREES], 0.5);
        self::assertGreaterThan(0.0, $tree[K::KEY_TREE_SHADE]);
        self::assertLessThan(0.0, $tree[K::KEY_SURFACE_TEMPERATURE], 'its shade cools the ground');
        self::assertEqualsWithDelta(0.0, $tree[K::KEY_SURFACE_MATERIAL], 1e-9, 'a tree does not change the surface beneath it');
        self::assertEqualsWithDelta(-$tree[K::KEY_SHADE_COOLING], $tree[K::KEY_SURFACE_TEMPERATURE], 1e-9, 'all of it is shade');
        self::assertLessThan(0.0, $tree[K::KEY_PET]);
        self::assertLessThan(0.0, $tree[K::KEY_EXTREME_HEAT]);
        self::assertGreaterThan(0.0, $tree[K::KEY_AIR_COOLING]);
        self::assertLessThan(0.0, $tree[K::KEY_RUNOFF], 'the crown holds rain back');
        self::assertNotSame([], $report->warnings, 'young trees are not yet mature');
        self::assertEquals($report->design->minus($report->baseline), $tree, 'the only measure: its contribution is the whole change');
    }

    #[Test]
    public function aRainGardenTakesTheRunoffOfTheSealedGroundAroundIt(): void
    {
        $report = $this->calculator()->calculate($this->site([[O::TYPE => O::RAINGARDEN, O::POINTS => [[0, 0], [5000, 0], [5000, 5000], [0, 5000]]]]));
        self::assertNotNull($report);
        $garden = $report->contributionOf(O::RAINGARDEN);

        // 25 m² of garden take the runoff of 250 m² of asphalt: 250 x 0.79 x 0.8, plus their own rain
        self::assertLessThan(-150.0, $garden[K::KEY_RUNOFF]);
        self::assertGreaterThan(130.0, $garden[K::KEY_INFILTRATION]);
        self::assertGreaterThan(0.0, $garden[K::KEY_EVAPOTRANSPIRATION]);
        self::assertLessThan(0.0, $garden[K::KEY_SURFACE_MATERIAL], 'a planted bed instead of asphalt');
        self::assertEqualsWithDelta(
            $report->design->value(K::KEY_RUNOFF) + $report->design->value(K::KEY_INFILTRATION) + $report->design->value(K::KEY_EVAPORATION),
            900 * 0.79,
            0.01,
            'no rain is lost or made',
        );
    }

    #[Test]
    public function withoutAPlotThereIsNothingToCalculate(): void
    {
        self::assertNull($this->calculator()->calculate(new Site([])));
    }

    private function calculator(): CoolingCalculator
    {
        return new CoolingCalculator(new ImpactCalculator());
    }

    /**
     * @param list<array<string, mixed>> $objects
     */
    private function site(array $objects, string $surface = O::SURFACE_ASPHALT): Site
    {
        return Site::fromJson((new SiteType())->normalize(['objects' => [[O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => $surface], ...$objects]]));
    }
}

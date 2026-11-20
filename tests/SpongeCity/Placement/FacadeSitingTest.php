<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Placement;

use App\Mcp\CoolingTools;
use App\Mcp\PlacementTools;
use App\Mcp\SpongeCityTools;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Product\SpongeCity\Funding\HamburgFunding;
use Core\Configurator\Product\SpongeCity\Impact\CoolingCalculator;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator as I;
use Core\Configurator\Product\SpongeCity\SpongeCitySku as Sku;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Façade greening on the sunny walls, and cool surfaces on asphalt that stays: a 60 × 40 m asphalt
 * yard with a school in its north half, its long south wall facing the yard.
 */
final class FacadeSitingTest extends KernelTestCase
{
    private const array PLOT = [[-30000, -20000], [30000, -20000], [30000, 20000], [-30000, 20000]];
    /** 30 × 10 m, its south wall at z = -5 m facing the yard */
    private const array SCHOOL = [[-15000, -15000], [15000, -15000], [15000, -5000], [-15000, -5000]];

    #[Test]
    public function theSunnyWallsAreProposedTheSouthWallFirst(): void
    {
        $proposal = $this->tools()->proposePlacements($this->plan()->id, O::FACADEGREEN);

        $first = $proposal['candidates'][0];
        self::assertSame('south', $first['metrics']['facing']);
        self::assertEqualsWithDelta(29.0, $first['metrics']['length_m'], 0.1, 'the wall, short of its corners');
        self::assertSame(9.0, $first['metrics']['height_m'], 'up to the eaves, in half metres');
        foreach ($first[O::POINTS] as [$x, $z]) {
            self::assertGreaterThan(-5000, $z, 'just outside the south wall');
            self::assertLessThan(-4500, $z);
        }
        $facings = array_column(array_column($proposal['candidates'], 'metrics'), 'facing');
        self::assertNotContains('north', $facings);
        self::assertContains('west', $facings);
        self::assertArrayHasKey('facing north or east', $proposal['excluded_share_of_plot']);
        self::assertEqualsWithDelta($first['metrics']['area_m2'] * CoolingCalculator::REFERENCE_EVAPOTRANSPIRATION_MM * CoolingCalculator::FACADE_CROP_COEFFICIENT, $first['metrics']['evapotranspiration_l_per_day'], 1.0);
    }

    #[Test]
    public function aPlacedGreeningEvaporatesIsPricedPerWallAndFundedButLeavesTheGroundAsItIs(): void
    {
        $plan = $this->plan();
        $tools = $this->tools();
        $container = static::getContainer();
        $impactBefore = $container->get(SpongeCityTools::class)->calculateImpact($plan->id);

        $first = $tools->proposePlacements($plan->id, O::FACADEGREEN)['candidates'][0];
        self::assertTrue($tools->placeCandidate($plan->id, $first['id'])['placed']);

        $cooling = $container->get(CoolingTools::class)->calculateCooling($plan->id);
        self::assertEqualsWithDelta($first['metrics']['evapotranspiration_l_per_day'], $cooling['evaporation_by_ground_l_per_day']['design'][CoolingCalculator::FACADE_EVAPORATION], 1.0);
        $after = $container->get(SpongeCityTools::class)->calculateImpact($plan->id);
        self::assertSame(array_column($impactBefore['metrics'], 'design', 'key'), array_column($after['metrics'], 'design', 'key'), 'not the ground');
        $line = array_find($after['cost']['lines'], static fn (array $l): bool => Sku::FACADE_GREEN === $l['sku']);
        self::assertEqualsWithDelta($first['metrics']['area_m2'], $line['amount'], 1.0);
        self::assertContains(HamburgFunding::FACADE, array_column($after['cost']['funding']['programmes'], 'key'));

        $again = $tools->proposePlacements($plan->id, O::FACADEGREEN)['candidates'];
        self::assertNotContains('south', array_column(array_column($again, 'metrics'), 'facing'), 'greened already');
    }

    #[Test]
    public function aCoolSurfaceStaysSealedButHeatsUpLess(): void
    {
        $plan = $this->plan();
        $tools = static::getContainer()->get(SpongeCityTools::class);
        $tools->addObjects($plan->id, [[O::TYPE => O::COOLSURFACE, O::POINTS => [[-20000, 0], [20000, 0], [20000, 15000], [-20000, 15000]]]]);

        $metrics = array_column($tools->calculateImpact($plan->id)['metrics'], null, 'key');

        self::assertLessThan(0.0, $metrics[I::METRIC_SURFACE_TEMPERATURE]['delta']);
        self::assertSame(0.0, (float) $metrics[I::METRIC_RETENTION]['delta'], 'it sheds the same rain');
        self::assertSame(0.0, (float) $metrics[I::METRIC_PERMEABLE_SHARE]['delta']);
    }

    private function plan(): Plan
    {
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Schoolyard', 53.55, 10.02);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::BUILDING, O::LABEL => 'School', O::POINTS => self::SCHOOL, O::HEIGHT => 9200],
        ]]));
        $plans->save($plan);

        return $plan;
    }

    private function tools(): PlacementTools
    {
        return static::getContainer()->get(PlacementTools::class);
    }
}

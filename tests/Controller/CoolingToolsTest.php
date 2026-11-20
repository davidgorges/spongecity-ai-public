<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Mcp\CoolingTools;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Product\SpongeCity\Impact\CoolingScenario as K;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The agent's cooling tool as the MCP server calls it: today against the plan, and what the trees alone do.
 */
final class CoolingToolsTest extends KernelTestCase
{
    #[Test]
    public function reportsTheCoolingOfThePlanAndOfEachMeasure(): void
    {
        $container = static::getContainer();
        $plans = $container->get(PlanStore::class);
        $plan = $plans->create('Schulhof', 53.5513, 10.0188);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => [[-20000, -20000], [20000, -20000], [20000, 20000], [-20000, 20000]], O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::NEWTREE, O::X => -5000, O::Z => 0, O::HEIGHT => 12000],
            [O::TYPE => O::NEWTREE, O::X => 5000, O::Z => 0, O::HEIGHT => 12000],
            [O::TYPE => O::PLANTING, O::POINTS => [[0, 10000], [8000, 10000], [8000, 16000], [0, 16000]]],
        ]]));
        $plans->save($plan);

        $report = $container->get(CoolingTools::class)->calculateCooling($plan->id, 600);

        self::assertSame(600.0, $report['annual_precipitation_mm']);
        self::assertLessThan(0.0, $report['delta'][K::KEY_SURFACE_TEMPERATURE]);
        self::assertGreaterThan(0.0, $report['delta'][K::KEY_EVAPOTRANSPIRATION]);
        $measures = array_column($report['measures'], null, 'type');
        self::assertSame(2, $measures[O::NEWTREE]['count']);
        self::assertGreaterThan(0.0, $measures[O::NEWTREE][K::KEY_EVAPOTRANSPIRATION_TREES]);
        self::assertSame(0.0, $measures[O::PLANTING][K::KEY_EVAPOTRANSPIRATION_TREES], 'planting has no crowns');
        self::assertNotEmpty($report['assumptions']);
    }

    #[Test]
    public function aPlanWithoutAPlotSaysSo(): void
    {
        $container = static::getContainer();
        $plan = $container->get(PlanStore::class)->create('Leer', 53.55, 10.0);
        $plan->replaceSite((new SiteType())->normalize(['objects' => []]));
        $container->get(PlanStore::class)->save($plan);

        self::assertArrayHasKey('error', $container->get(CoolingTools::class)->calculateCooling($plan->id));
    }
}

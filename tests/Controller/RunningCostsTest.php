<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Mcp\SpongeCityTools;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Product\SpongeCity\SpongeCityDefinition;
use Core\Configurator\Product\SpongeCity\SpongeCitySku as Sku;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The cost of calculate_impact as the agent gets it: the investment, what the measures cost a year
 * to keep working, and both added up over the lifecycle.
 */
final class RunningCostsTest extends KernelTestCase
{
    #[Test]
    public function addsTheRunningCostsOfEveryMeasureOverTheLifecycle(): void
    {
        $container = static::getContainer();
        $plans = $container->get(PlanStore::class);
        $plan = $plans->create('Schulhof', 53.5513, 10.0188);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => [[-20000, -20000], [20000, -20000], [20000, 20000], [-20000, 20000]], O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::NEWTREE, O::X => -5000, O::Z => 0, O::HEIGHT => 12000],
            [O::TYPE => O::NEWTREE, O::X => 5000, O::Z => 0, O::HEIGHT => 12000],
            // 10 m × 5 m = 50 m²
            [O::TYPE => O::RAINGARDEN, O::POINTS => [[0, 10000], [10000, 10000], [10000, 15000], [0, 15000]]],
        ]]));
        $plans->save($plan);

        $cost = $container->get(SpongeCityTools::class)->calculateImpact($plan->id)['cost'];

        $lines = array_column($cost['lines'], null, 'sku');
        $treeCare = SpongeCityDefinition::maintenancePrices()->priceOf(Sku::TREE) / 100;
        $gardenCare = SpongeCityDefinition::maintenancePrices()->priceOf(Sku::RAINGARDEN) / 100;
        self::assertEqualsWithDelta(2 * $treeCare, $lines[Sku::TREE]['maintenance_per_year'], 0.01);
        self::assertEqualsWithDelta(50 * $gardenCare, $lines[Sku::RAINGARDEN]['maintenance_per_year'], 0.5);
        self::assertEqualsWithDelta($lines[Sku::TREE]['maintenance_per_year'] + $lines[Sku::RAINGARDEN]['maintenance_per_year'], $cost['maintenance_per_year'], 0.01);
        self::assertSame(SpongeCityDefinition::LIFECYCLE_YEARS, $cost['lifecycle_years']);
        self::assertEqualsWithDelta($cost['total'] + SpongeCityDefinition::LIFECYCLE_YEARS * $cost['maintenance_per_year'], $cost['lifecycle_total'], 0.01);
        self::assertStringContainsString((string) SpongeCityDefinition::LIFECYCLE_YEARS, $cost['maintenance_basis']);
    }

    #[Test]
    public function everyPricedMeasureHasARunningCost(): void
    {
        $prices = (new SpongeCityDefinition())->defaultPrices()->toArray();

        self::assertSame(array_keys($prices), array_keys(SpongeCityDefinition::maintenancePrices()->toArray()), 'a measure without running costs would look cheaper over the lifecycle than it is');
    }
}

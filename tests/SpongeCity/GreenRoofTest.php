<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity;

use App\Mcp\CoolingTools;
use App\Mcp\PlacementTools;
use App\Mcp\SpongeCityTools;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanAnalyzer;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Assembly\Measure;
use Core\Configurator\Product\SpongeCity\GreenRoof\GreenRoofs;
use Core\Configurator\Product\SpongeCity\Impact\CoolingScenario as K;
use Core\Configurator\Product\SpongeCity\Impact\GroundClass;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\SpongeCitySku;
use Core\Configurator\Scenery\Building;
use Core\Configurator\Site\BuildingType;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Green roofs on a schoolyard: a two-storey school block and a gym with flat roofs, a garage, a
 * pitched house, and a neighbour's block beyond the plot. Only roofs that can carry one get one.
 */
final class GreenRoofTest extends KernelTestCase
{
    private const array PLOT = [[-50000, -40000], [50000, -40000], [50000, 40000], [-50000, 40000]];
    /** 30 x 20 m: 600 m² of roof, 550 m² planted inside the 0.5 m strip along its 100 m of edge */
    private const string SCHOOL = 'school';
    private const string GYM = 'gym';
    private const string GARAGE = 'garage';
    private const string HOUSE = 'house';
    private const string NEIGHBOUR = 'neighbour';

    #[Test]
    public function onlyTheFlatRoofsOfThePlotCanBePlantedAndARoofGardenNeverOnAHall(): void
    {
        $site = $this->site();
        $assess = static fn (string $id) => GreenRoofs::assessIn($site, $id);

        $school = $assess(self::SCHOOL);
        self::assertNotNull($school);
        self::assertSame([O::KIND_EXTENSIVE, O::KIND_INTENSIVE], $school->kinds);
        self::assertEqualsWithDelta(600.0, $school->grossSquareMetres(), 0.01);
        self::assertEqualsWithDelta(550.0, $school->plantedSquareMetres(), 0.01);
        self::assertSame([O::KIND_EXTENSIVE], $assess(self::GYM)?->kinds, 'a hall: no roof garden');
        self::assertTrue($assess(self::GYM)?->isHall);
        self::assertFalse($assess(self::GARAGE)?->isEligible());
        self::assertStringContainsString('pitched', (string) $assess(self::HOUSE)?->reasons[0]);
        self::assertFalse($assess(self::NEIGHBOUR)?->onPlot);
    }

    #[Test]
    public function aStairHeadOnTheRoofStaysRoofAndIsNotCounted(): void
    {
        $block = $this->building(self::SCHOOL, [[0, 0], [30000, 0], [30000, 20000], [0, 20000]], 8000, 2, BuildingType::School, [
            [O::POINTS => [[0, 0], [30000, 0], [30000, 20000], [0, 20000]], O::HEIGHT => 8000],
            // a 4 x 4 m stair head: too small to plant, it covers 16 m² of the roof below
            [O::POINTS => [[10000, 8000], [14000, 8000], [14000, 12000], [10000, 12000]], O::HEIGHT => 11000],
        ]);

        $levels = GreenRoofs::levels($block);

        self::assertCount(1, $levels);
        self::assertEqualsWithDelta(600.0 - 16.0, $levels[0]->grossSquareMetres, 0.01);
    }

    #[Test]
    public function theAgentIsOfferedTheEligibleRoofsAndTheOthersAreNamed(): void
    {
        $container = static::getContainer();
        $plan = $this->plan();

        $proposal = $container->get(PlacementTools::class)->proposePlacements($plan->id, O::GREENROOF);

        self::assertSame([self::SCHOOL, self::GYM], array_map(static fn (array $c): string => $c['metrics']['building'], $proposal['candidates']));
        $school = $proposal['candidates'][0]['metrics'];
        self::assertTrue($school['intensive_possible']);
        // 550 m² x (0.9 - 0.5) x 40 mm
        self::assertEqualsWithDelta(8.8, $school['retained_m3_design_storm'], 0.05);
        // 600 m² x 95 € + 8,000 € set-up
        self::assertEqualsWithDelta(65000.0, $school['cost_eur'], 100.0);
        self::assertStringContainsString(self::GARAGE, $proposal['note']);
        self::assertStringNotContainsString(self::NEIGHBOUR, $proposal['note'], 'a neighbour\'s roof is not ours to mention');

        $placed = $container->get(PlacementTools::class)->placeCandidate($plan->id, $proposal['candidates'][0]['id'], why: 'Largest flat roof.');
        self::assertTrue($placed['placed']);
        $again = $container->get(PlacementTools::class)->proposePlacements($plan->id, O::GREENROOF);
        self::assertSame([self::GYM], array_map(static fn (array $c): string => $c['metrics']['building'], $again['candidates']), 'one per building');
    }

    #[Test]
    public function addObjectsRefusesARoofThatCannotCarryIt(): void
    {
        $tools = static::getContainer()->get(SpongeCityTools::class);
        $plan = $this->plan();

        $garage = $tools->addObjects($plan->id, [[O::TYPE => O::GREENROOF, O::BUILDING_ID => self::GARAGE]]);
        $gymGarden = $tools->addObjects($plan->id, [[O::TYPE => O::GREENROOF, O::BUILDING_ID => self::GYM, O::KIND => O::KIND_INTENSIVE]]);
        $gym = $tools->addObjects($plan->id, [[O::TYPE => O::GREENROOF, O::BUILDING_ID => self::GYM]]);

        self::assertSame([], $garage['ids']);
        self::assertStringContainsString('garage', $garage['conflicts'][0]['reasons'][0]);
        self::assertSame([], $gymGarden['ids'], 'no roof garden on the hall');
        self::assertCount(1, $gym['ids']);
        $object = array_find(Site::fromJson(static::getContainer()->get(PlanStore::class)->get($plan->id)->siteJson())->objects, static fn (array $o): bool => $o[O::ID] === $gym['ids'][0]);
        self::assertNotNull($object);
        self::assertCount(4, $object[O::POINTS], 'the outline is the building\'s');
        self::assertFalse($tools->updateObject($plan->id, $gym['ids'][0], [O::KIND => O::KIND_INTENSIVE])['updated']);
        self::assertSame([], $tools->addObjects($plan->id, [[O::TYPE => O::GREENROOF, O::BUILDING_ID => self::GYM]])['ids'], 'one per building');
    }

    #[Test]
    public function aGreenRoofHoldsBackRainCoolsTheRoofAndIsPricedWithItsSetUp(): void
    {
        $container = static::getContainer();
        $plan = $this->plan();
        $container->get(SpongeCityTools::class)->addObjects($plan->id, [[O::TYPE => O::GREENROOF, O::BUILDING_ID => self::SCHOOL]]);
        $plan = $container->get(PlanStore::class)->get($plan->id);
        $analyzer = $container->get(PlanAnalyzer::class);

        $impact = $analyzer->impact($plan);
        self::assertEqualsWithDelta(550.0, $impact->areasSquareMetres[GroundClass::GreenRoofExtensive->value] ?? 0.0, 15.0, 'planted inside the strip');
        self::assertLessThan(0.0, $impact->metric(ImpactCalculator::METRIC_RUNOFF)?->delta());
        self::assertEqualsWithDelta(0.0, (float) $impact->metric(ImpactCalculator::METRIC_SURFACE_TEMPERATURE)?->delta(), 1e-9, 'the yard is the same yard');

        $cooling = $container->get(CoolingTools::class)->calculateCooling($plan->id);
        self::assertLessThan(0.0, $cooling['delta'][K::KEY_ROOF_TEMPERATURE]);
        self::assertLessThan(0.0, $cooling['delta'][K::KEY_SITE_SURFACE_TEMPERATURE]);
        self::assertGreaterThan(0.0, $cooling['delta'][K::KEY_EVAPOTRANSPIRATION]);
        self::assertSame(O::GREENROOF, $cooling['measures'][0]['type']);

        $lines = array_column($analyzer->cost($plan)['lines'], null, 'sku');
        self::assertEqualsWithDelta(600.0, $lines[SpongeCitySku::GREENROOF_EXTENSIVE]['amount'], 0.5, 'priced per m² of roof, strip included');
        self::assertSame(1.0, $lines[SpongeCitySku::GREENROOF_SETUP]['amount']);
        self::assertEqualsWithDelta(65000.0, $lines[SpongeCitySku::GREENROOF_EXTENSIVE]['total'] + $lines[SpongeCitySku::GREENROOF_SETUP]['total'], 1.0);
        self::assertSame(Measure::PIECE, $lines[SpongeCitySku::GREENROOF_SETUP]['unit'], 'the set-up is counted, not measured');
        self::assertEqualsWithDelta([65000.0], array_values($analyzer->costsByMeasure($plan)), 1.0, 'the measure\'s own cost, set-up included');
    }

    private function plan(): Plan
    {
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Schulhof', 53.55, 10.0);
        $plan->replaceSite((new SiteType())->normalize(['objects' => $this->site()->objects]));
        $plans->save($plan);

        return $plan;
    }

    private function site(): Site
    {
        return Site::fromJson((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
            $this->building(self::SCHOOL, [[-40000, -30000], [-10000, -30000], [-10000, -10000], [-40000, -10000]], 8000, 2, BuildingType::School),
            $this->building(self::GYM, [[10000, -30000], [30000, -30000], [30000, -5000], [10000, -5000]], 7000, 1, BuildingType::Industrial),
            $this->building(self::GARAGE, [[40000, 30000], [44000, 30000], [44000, 33000], [40000, 33000]], 2500, 1, BuildingType::Garage),
            [...$this->building(self::HOUSE, [[-40000, 20000], [-30000, 20000], [-30000, 30000], [-40000, 30000]], 6000, 2, BuildingType::House), O::ROOF => Building::ROOF_GABLE],
            $this->building(self::NEIGHBOUR, [[60000, 0], [80000, 0], [80000, 20000], [60000, 20000]], 12000, 4, BuildingType::Apartments),
        ]]));
    }

    /**
     * @param list<array{0: int, 1: int}>                                         $points
     * @param list<array{points: list<array{0: int, 1: int}>, height: int}> $parts
     *
     * @return array<string, mixed>
     */
    private function building(string $id, array $points, int $height, int $storeys, BuildingType $use, array $parts = []): array
    {
        return [O::ID => $id, O::TYPE => O::BUILDING, O::POINTS => $points, O::HEIGHT => $height, O::STOREYS => $storeys, O::USE => $use->value, O::ROOF => Building::ROOF_FLAT, O::PARTS => $parts];
    }
}

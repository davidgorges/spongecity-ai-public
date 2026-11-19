<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Placement;

use App\Mcp\PlacementTools;
use App\SpongeCity\Conditions\SiteConditions;
use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Geo\Hamburg\HamburgTerrain;
use App\SpongeCity\Placement\OpenGround;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\Terrain\Terrain;
use App\SpongeCity\Terrain\TerrainGrid;
use App\SpongeCity\Terrain\TerrainStore;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Rain gardens proposed where the water runs, by the rules, the way the agent asks for them: an
 * 80 × 60 m sealed yard whose ground falls towards one bowl, a building and a street tree.
 */
final class RainGardenSitingTest extends KernelTestCase
{
    private const array PLOT = [[-40000, -30000], [40000, -30000], [40000, 30000], [-40000, 30000]];
    /** 20 × 20 m, its east wall at x = -10 m */
    private const array BUILDING = [[-30000, -20000], [-10000, -20000], [-10000, 0], [-30000, 0]];
    /** a street tree with a 6 m crown: its root zone reaches 4.5 m */
    private const array TREE = [-5000, 18000];

    #[Test]
    public function theFirstCandidateSitsWhereTheYardsWaterEnds(): void
    {
        $plan = $this->plan(bowl: [20.0, 10.0]);

        $proposal = $this->tools()->proposePlacements($plan->id, O::RAINGARDEN);

        $first = $proposal['candidates'][0];
        self::assertSame('raingarden-1', $first['id']);
        self::assertTrue(Polygon::contains($first[O::POINTS], 20000, 10000), 'in the bowl');
        self::assertGreaterThan(2000, $first['metrics']['catchment_m2'], 'most of the yard drains to it');
        self::assertEqualsWithDelta(150.0, $first['metrics']['area_m2'], 6.0, 'sized to its catchment, at most 150 m² (a little less once its edge is rounded)');
        self::assertGreaterThan(8, count($first[O::POINTS]), 'a rounded basin, not a rectangle');
        self::assertLessThanOrEqual(O::MAX_POINTS, count($first[O::POINTS]));
        self::assertCount(1, $proposal['candidates'], 'all the water ends in one bowl: a second garden would only take from the first');
    }

    #[Test]
    public function onAnEvenSlopeTheGardenFollowsTheContourAcrossTheFlow(): void
    {
        // the yard falls 3 cm per metre to the south, where the water leaves it over the edge
        $candidate = $this->tools()->proposePlacements($this->plan(slope: true)->id, O::RAINGARDEN, areaM2: 60.0)['candidates'][0];

        $xs = array_column($candidate[O::POINTS], 0);
        $zs = array_column($candidate[O::POINTS], 1);
        self::assertGreaterThan(1.5 * (max($zs) - min($zs)), max($xs) - min($xs), 'wider along the contour than down the slope, like a swale');
        self::assertGreaterThan(20000, min($zs), 'at the foot of the yard');
    }

    #[Test]
    public function aLinedGardenMayComeCloserToABuildingThanOneThatInfiltrates(): void
    {
        // the bowl 3 m from the building's east wall
        $lined = $this->tools()->proposePlacements($this->plan(bowl: [-7.0, -10.0])->id, O::RAINGARDEN)['candidates'][0];
        $infiltrating = $this->tools()->proposePlacements($this->plan(bowl: [-7.0, -10.0], infiltration: 'likely')->id, O::RAINGARDEN)['candidates'][0];

        self::assertTrue($lined['metrics']['lined']);
        self::assertGreaterThanOrEqual(1.5, $this->wallDistance($lined[O::POINTS]));
        self::assertFalse($infiltrating['metrics']['lined']);
        self::assertSame('likely', $infiltrating['metrics']['infiltration']);
        self::assertGreaterThanOrEqual(5.5, $this->wallDistance($infiltrating[O::POINTS]), 'DWA-A 138: well away from the cellar');
    }

    #[Test]
    public function aTreesRootZoneIsLeftAlone(): void
    {
        $candidate = $this->tools()->proposePlacements($this->plan(bowl: [-5.0, 18.0])->id, O::RAINGARDEN)['candidates'][0];

        foreach ($candidate[O::POINTS] as [$x, $z]) {
            self::assertGreaterThanOrEqual(4000, hypot($x - self::TREE[0], $z - self::TREE[1]), 'outside the crown plus 1.5 m');
        }
    }

    #[Test]
    public function aSwingInTheBowlKeepsItsFallZone(): void
    {
        $plan = $this->plan(bowl: [20.0, 10.0]);
        $editor = static::getContainer()->get(SiteEditor::class);
        $editor->add($plan->id, [O::equipment(O::EQUIPMENT_SWING, 20000, 10000)]);
        $fallZone = Site::outlinesOf($editor->site($plan->id)->ofType(O::EQUIPMENT)[0])[0];

        $proposal = $this->tools()->proposePlacements($plan->id, O::RAINGARDEN);

        self::assertNotEmpty($proposal['candidates'], 'the water still gathers beside it');
        foreach ($proposal['candidates'] as $candidate) {
            self::assertEqualsWithDelta(0.0, Polygon::overlapArea($candidate[O::POINTS], $fallZone) / 1e6, 1.0, 'not in the fall zone');
        }
        self::assertArrayHasKey(OpenGround::EQUIPMENT, $proposal['excluded_share_of_plot']);
    }

    #[Test]
    public function aPlayAreaGetsNoRainGarden(): void
    {
        $plan = $this->plan(bowl: [20.0, 10.0]);
        $playground = [[10000, 0], [30000, 0], [30000, 20000], [10000, 20000]];
        static::getContainer()->get(SiteEditor::class)->add($plan->id, [[O::TYPE => O::AREA, O::POINTS => $playground, O::USE => O::AREA_PLAY, O::SURFACE => O::SURFACE_SOIL]]);

        $proposal = $this->tools()->proposePlacements($plan->id, O::RAINGARDEN);

        foreach ($proposal['candidates'] as $candidate) {
            self::assertEqualsWithDelta(0.0, Polygon::overlapArea($candidate[O::POINTS], $playground) / 1e6, 1.0, 'off the playground');
        }
        self::assertArrayHasKey(OpenGround::PLAY_AREA, $proposal['excluded_share_of_plot']);
    }

    #[Test]
    public function theAgentPlacesACandidateByItsIdAndCannotPlaceItTwice(): void
    {
        $plan = $this->plan(bowl: [20.0, 10.0]);
        $tools = $this->tools();
        $candidate = $tools->proposePlacements($plan->id, O::RAINGARDEN)['candidates'][0];

        $placed = $tools->placeCandidate($plan->id, $candidate['id'], 'Rain garden in the bowl');
        $again = $tools->placeCandidate($plan->id, $candidate['id']);

        self::assertTrue($placed['placed']);
        $gardens = static::getContainer()->get(SiteEditor::class)->site($plan->id)->ofType(O::RAINGARDEN);
        self::assertCount(1, $gardens);
        self::assertSame('Rain garden in the bowl', $gardens[0][O::LABEL]);
        self::assertEquals($candidate[O::POINTS], $gardens[0][O::POINTS]);
        self::assertFalse($again['placed']);
        self::assertStringContainsString('Propose again', $again['error']);
    }

    #[Test]
    public function waterAlreadyCaughtIsNotProposedAgain(): void
    {
        $plan = $this->plan(bowl: [20.0, 10.0]);
        $tools = $this->tools();
        $tools->placeCandidate($plan->id, $tools->proposePlacements($plan->id, O::RAINGARDEN)['candidates'][0]['id']);

        $next = $tools->proposePlacements($plan->id, O::RAINGARDEN);

        self::assertSame([], $next['candidates'], 'everything upstream of the bowl drains into the garden there');
        self::assertArrayHasKey('note', $next);
    }

    #[Test]
    public function withoutTerrainNothingIsProposed(): void
    {
        $plan = $this->plan(bowl: null);

        $proposal = $this->tools()->proposePlacements($plan->id, O::RAINGARDEN);

        self::assertSame([], $proposal['candidates']);
        self::assertStringContainsString('No terrain model', $proposal['note']);
    }

    #[Test]
    public function otherTypesAreStillDrawnByHand(): void
    {
        $result = $this->tools()->proposePlacements($this->plan(bowl: null)->id, O::NEWTREE);

        self::assertStringContainsString('add_objects', $result['error']);
        self::assertEqualsCanonicalizing([O::RAINGARDEN, O::PERMEABLE], $result['types']);
    }

    #[Test]
    public function aPlaceTheUserNamedNarrowsTheSearch(): void
    {
        $plan = $this->plan(bowl: [20.0, 10.0]);

        $far = $this->tools()->proposePlacements($plan->id, O::RAINGARDEN, nearX: -35000, nearZ: 25000);

        foreach ($far['candidates'] as $candidate) {
            self::assertFalse(Polygon::contains($candidate[O::POINTS], 20000, 10000), 'the bowl is beyond 30 m of the point');
        }
    }

    /**
     * @param array{0: float, 1: float}|null $bowl  where the ground is lowest, plan metres; null: no terrain (unless slope)
     * @param bool                           $slope instead of a bowl, ground falling evenly to the south
     */
    private function plan(?array $bowl = null, ?string $infiltration = null, bool $slope = false): Plan
    {
        $container = static::getContainer();
        $plans = $container->get(PlanStore::class);
        $plan = $plans->create('Yard', 53.55, 10.02);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::BUILDING, O::LABEL => 'School', O::POINTS => self::BUILDING, O::HEIGHT => 12000],
            [O::TYPE => O::TREE, O::X => self::TREE[0], O::Z => self::TREE[1], O::HEIGHT => 12000, O::DIAMETER => 6000],
        ]]));
        $plans->save($plan);
        if (null !== $bowl || $slope) {
            // 2 m nodes from -60 to +60 m; the ground rises 3 cm per metre away from the bowl (or northwards)
            $heights = [];
            for ($r = 0; $r < 61; ++$r) {
                for ($c = 0; $c < 61; ++$c) {
                    [$x, $z] = [-60 + 2 * $c, -60 + 2 * $r];
                    $heights[] = (int) round(null === $bowl ? -3 * $z : 3 * hypot($x - $bowl[0], $z - $bowl[1]));
                }
            }
            $grid = new TerrainGrid(-60.0, -60.0, 2.0, 61, 61, $heights);
            $container->get(TerrainStore::class)->save($plan->id, new Terrain(5.0, $grid, $grid, HamburgTerrain::source()));
        }
        if (null !== $infiltration) {
            $container->get(SiteConditionsStore::class)->save($plan->id, new SiteConditions([[
                SiteConditions::POINTS => [[-50.0, -40.0], [50.0, -40.0], [50.0, 40.0], [-50.0, 40.0]],
                SiteConditions::HOLES => [],
                SiteConditions::POTENTIAL => $infiltration,
                SiteConditions::DEPTH => '2 - 5 m',
                SiteConditions::FACILITY => 'Flächen- / Muldenversickerung',
            ]]));
        }

        return $plan;
    }

    /**
     * @param list<array{0: int, 1: int}> $points
     *
     * @return float metres from the building's east wall to the nearest corner east of it
     */
    private function wallDistance(array $points): float
    {
        return min(array_map(static fn (array $p): float => ($p[0] - self::BUILDING[1][0]) / 1000, $points));
    }

    private function tools(): PlacementTools
    {
        return static::getContainer()->get(PlacementTools::class);
    }
}

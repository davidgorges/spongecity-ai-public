<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Placement;

use App\Mcp\PlacementTools;
use App\Mcp\SpongeCityTools;
use App\SpongeCity\Conditions\SiteConditions;
use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Placement\OpenGround;
use App\SpongeCity\Placement\TreeSiting;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Product\SpongeCity\SpongeCitySku as Sku;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Shade trees proposed where their crown shades sunlit open ground, by the rules: a 60 × 40 m
 * asphalt yard with a school, a playground and an old tree.
 */
final class TreeSitingTest extends KernelTestCase
{
    private const array PLOT = [[-30000, -20000], [30000, -20000], [30000, 20000], [-30000, 20000]];
    private const array SCHOOL = [[-28000, -18000], [-8000, -18000], [-8000, -8000], [-28000, -8000]];
    private const array PLAYGROUND = [[10000, 5000], [28000, 5000], [28000, 18000], [10000, 18000]];
    private const array OLD_TREE = [0, 0, 12000, 10000];

    #[Test]
    public function treesGoOnFreeGroundWhereTheirShadeAddsTheMost(): void
    {
        $plan = $this->plan();

        $proposal = $this->tools()->proposePlacements($plan->id, O::NEWTREE, count: 3);

        self::assertCount(3, $proposal['candidates']);
        $height = (float) O::fields(O::NEWTREE)[O::HEIGHT][3];
        $radius = $height * ImpactCalculator::CANOPY_RADIUS_SHARE / 1000;
        $previous = INF;
        foreach ($proposal['candidates'] as $candidate) {
            $m = $candidate['metrics'];
            $x = $m['x'] / 1000;
            $z = $m['z'] / 1000;
            self::assertGreaterThan(0.0, $m['shade_added_points']);
            self::assertLessThanOrEqual($previous, $m['shade_added_points'], 'the most shade first');
            $previous = $m['shade_added_points'];
            self::assertGreaterThanOrEqual($radius + TreeSiting::WALL_GAP_METRES, $m['distance_to_building_m']);
            self::assertFalse(Polygon::contains(self::metres(self::PLAYGROUND), $x, $z), 'not on the playground');
            self::assertGreaterThanOrEqual($radius + self::OLD_TREE[3] / 2000, hypot($x - self::OLD_TREE[0] / 1000, $z - self::OLD_TREE[1] / 1000), 'the crowns apart');
        }
        $a = $proposal['candidates'][0]['metrics'];
        $b = $proposal['candidates'][1]['metrics'];
        self::assertGreaterThanOrEqual(2 * $radius, hypot($a['x'] - $b['x'], $a['z'] - $b['z']) / 1000, 'the candidates do not crowd each other');
        self::assertArrayHasKey(OpenGround::PLAY_AREA, $proposal['excluded_share_of_plot']);
    }

    #[Test]
    public function whereWaterSoaksAwayATreeTrenchGoesBelowAndCounts(): void
    {
        $plan = $this->plan(infiltration: 'likely');
        $tools = $this->tools();

        $first = $tools->proposePlacements($plan->id, O::NEWTREE, count: 1)['candidates'][0];
        self::assertSame(TreeSiting::TRENCH_M3, $first['metrics']['trench_m3']);
        self::assertGreaterThan(0.0, $first['metrics']['trench_retained_m3']);
        self::assertStringContainsString('Baumrigole', implode(' ', $first['reasons']));

        $before = static::getContainer()->get(SpongeCityTools::class)->calculateImpact($plan->id);
        self::assertTrue($tools->placeCandidate($plan->id, $first['id'])['placed']);
        $after = static::getContainer()->get(SpongeCityTools::class)->calculateImpact($plan->id);

        $retained = static fn (array $impact): float => array_column($impact['metrics'], 'design', 'key')[ImpactCalculator::METRIC_RETENTION];
        self::assertGreaterThan($retained($before) + $first['metrics']['trench_retained_m3'] - 0.5, $retained($after), 'the trench holds its runoff');
        $trenchLine = array_find($after['cost']['lines'], static fn (array $l): bool => Sku::TREE_TRENCH === $l['sku']);
        self::assertEqualsWithDelta(TreeSiting::TRENCH_M3, $trenchLine['amount'], 0.01);

        $tree = Site::fromJson(static::getContainer()->get(PlanStore::class)->get($plan->id)->siteJson())->ofType(O::NEWTREE)[0];
        self::assertSame(TreeSiting::TRENCH_M3, $tree[O::TRENCH_VOLUME]);
    }

    #[Test]
    public function withoutInfiltrationThereIsNoTrench(): void
    {
        $first = $this->tools()->proposePlacements($this->plan()->id, O::NEWTREE, count: 1)['candidates'][0];

        self::assertSame(0, $first['metrics']['trench_m3']);
    }

    private function plan(?string $infiltration = null): Plan
    {
        $container = static::getContainer();
        $plans = $container->get(PlanStore::class);
        $plan = $plans->create('Schoolyard', 53.55, 10.02);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::BUILDING, O::POINTS => self::SCHOOL, O::HEIGHT => 12000],
            [O::TYPE => O::AREA, O::POINTS => self::PLAYGROUND, O::USE => O::AREA_PLAY, O::SURFACE => O::SURFACE_SOIL],
            [O::TYPE => O::TREE, O::X => self::OLD_TREE[0], O::Z => self::OLD_TREE[1], O::HEIGHT => self::OLD_TREE[2], O::DIAMETER => self::OLD_TREE[3]],
        ]]));
        $plans->save($plan);
        if (null !== $infiltration) {
            // plan metres, around the whole plot
            $container->get(SiteConditionsStore::class)->save($plan->id, new SiteConditions([[
                SiteConditions::POINTS => [[-40.0, -30.0], [40.0, -30.0], [40.0, 30.0], [-40.0, 30.0]],
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
     * @return list<array{0: float, 1: float}>
     */
    private static function metres(array $points): array
    {
        return array_map(static fn (array $p): array => [$p[0] / 1000, $p[1] / 1000], $points);
    }

    private function tools(): PlacementTools
    {
        return static::getContainer()->get(PlacementTools::class);
    }
}

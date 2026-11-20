<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Placement;

use App\Mcp\PlacementTools;
use App\SpongeCity\Placement\PermeableSiting;
use App\SpongeCity\Placement\PlantingSiting;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Planting where nobody needs to walk or drive: a strip along the façades of a yard, the trees'
 * root zones, quiet areas as a whole; car parks and play areas keep their surfaces. An 80 × 60 m
 * asphalt schoolyard with a school, a car park, a playground, a quiet corner and a tree.
 */
final class PlantingSitingTest extends KernelTestCase
{
    private const array PLOT = [[-40000, -30000], [40000, -30000], [40000, 30000], [-40000, 30000]];
    /** its south façade at z = -10 m */
    private const array SCHOOL = [[-35000, -25000], [-5000, -25000], [-5000, -10000], [-35000, -10000]];
    private const array CAR_PARK = [[15000, -28000], [38000, -28000], [38000, -12000], [15000, -12000]];
    private const array PLAYGROUND = [[15000, 8000], [38000, 8000], [38000, 28000], [15000, 28000]];
    private const array QUIET = [[-38000, 18000], [-25000, 18000], [-25000, 28000], [-38000, 28000]];
    private const array TREE = [-10000, 15000];

    #[Test]
    public function aYardGetsAStripAlongTheFacadeAndTheTreesRootZoneNeverTheWholeYard(): void
    {
        $proposal = $this->tools()->proposePlacements($this->plan(sealedYard: true)->id, O::PLANTING, count: 5);

        $yard = array_values(array_filter($proposal['candidates'], static fn (array $c): bool => O::AREA_YARD === $c['metrics']['zone_use']));
        $shapes = array_column(array_column($yard, 'metrics'), 'shape');
        self::assertContains(PlantingSiting::SHAPE_BAND, $shapes);
        self::assertContains(PlantingSiting::SHAPE_TREES, $shapes);
        self::assertNotContains(PlantingSiting::SHAPE_ZONE, $shapes, 'the middle of a yard stays open for play');

        $band = array_find($yard, static fn (array $c): bool => PlantingSiting::SHAPE_BAND === $c['metrics']['shape']);
        foreach ($band[O::POINTS] as [$x, $z]) {
            self::assertLessThanOrEqual(-10000 + 1000 + (PlantingSiting::BAND_METRES + 1) * 1000, $z, 'within the strip south of the façade, or beside the building');
        }
        self::assertGreaterThan(0.0, $band['metrics']['runoff_removed_m3']);
        self::assertGreaterThan(20.0, $band['metrics']['cooler_k'], 'asphalt is much hotter than planting');
        self::assertGreaterThan(0, $band['metrics']['cost_eur']);
        self::assertNotEmpty($band['metrics']['alternatives']);
        self::assertStringContainsString('rather than permeable pavers', implode(' ', $band['reasons']));
    }

    #[Test]
    public function aQuietAreaIsPlantedAsAWholeAndCarParksAndPlayAreasKeepTheirSurfaces(): void
    {
        $proposal = $this->tools()->proposePlacements($this->plan(sealedYard: true)->id, O::PLANTING, count: 5);

        $quiet = array_values(array_filter($proposal['candidates'], static fn (array $c): bool => O::AREA_QUIET === $c['metrics']['zone_use']));
        self::assertContains(PlantingSiting::SHAPE_ZONE, array_column(array_column($quiet, 'metrics'), 'shape'));
        self::assertSame([], array_values(array_filter($proposal['candidates'], static fn (array $c): bool => in_array($c['metrics']['zone_use'], [O::AREA_PARKING, O::AREA_PLAY], true))));
        self::assertArrayHasKey(PlantingSiting::NOT_PLANTED[O::AREA_PARKING], $proposal['excluded_share_of_plot']);
        self::assertArrayHasKey(PermeableSiting::KEPT[O::AREA_PLAY], $proposal['excluded_share_of_plot']);
    }

    #[Test]
    public function groundNobodyHasDescribedIsNotTouchedAndAPlacedBedIsPriced(): void
    {
        $unknown = $this->tools()->proposePlacements($this->plan(sealedYard: false)->id, O::PLANTING, count: 5);
        self::assertSame([], array_values(array_filter($unknown['candidates'], static fn (array $c): bool => O::AREA_YARD === $c['metrics']['zone_use'])));
        self::assertArrayHasKey(PermeableSiting::UNKNOWN_GROUND, $unknown['excluded_share_of_plot']);

        $plan = $this->plan(sealedYard: true);
        $first = $this->tools()->proposePlacements($plan->id, O::PLANTING)['candidates'][0];
        $placed = $this->tools()->placeCandidate($plan->id, $first['id']);
        self::assertTrue($placed['placed']);
        $bed = Site::fromJson(static::getContainer()->get(PlanStore::class)->get($plan->id)->siteJson())->ofType(O::PLANTING)[0];
        self::assertEqualsWithDelta($first['metrics']['area_m2'], Polygon::area(Site::points($bed)) / 1_000_000, 0.5);
    }

    private function plan(bool $sealedYard): Plan
    {
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Schoolyard', 53.55, 10.02);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::BUILDING, O::LABEL => 'School', O::POINTS => self::SCHOOL, O::HEIGHT => 12000],
            [O::TYPE => O::TREE, O::X => self::TREE[0], O::Z => self::TREE[1], O::HEIGHT => 12000, O::DIAMETER => 8000],
            [O::TYPE => O::AREA, O::POINTS => self::CAR_PARK, O::USE => O::AREA_PARKING, O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::AREA, O::POINTS => self::PLAYGROUND, O::USE => O::AREA_PLAY, O::SURFACE => O::SURFACE_SOIL],
            [O::TYPE => O::AREA, O::POINTS => self::QUIET, O::USE => O::AREA_QUIET, O::SURFACE => O::SURFACE_ASPHALT],
        ]]));
        $plans->save($plan);
        if ($sealedYard) {
            $yard = array_column($this->tools()->getZones($plan->id)['zones'], null, O::USE)[O::AREA_YARD];
            self::assertTrue($this->tools()->describeZone($plan->id, $yard['id'], surface: O::SURFACE_ASPHALT)['set']);
        }

        return $plan;
    }

    private function tools(): PlacementTools
    {
        return static::getContainer()->get(PlacementTools::class);
    }
}

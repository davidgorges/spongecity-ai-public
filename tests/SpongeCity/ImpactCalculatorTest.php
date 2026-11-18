<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\SiteImporter;
use App\SpongeCity\PlanEvaluator;
use Core\Configurator\Product\SpongeCity\Impact\GroundClass;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Scene\ModelCatalog;
use Core\Configurator\Scene\SceneSerializer;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImpactCalculatorTest extends KernelTestCase
{
    private const array CENTRE = [51.0, 7.0];
    private const int STORM = 40;

    #[Test]
    public function interventionsImproveTheBalance(): void
    {
        $site = $this->site([
            [O::TYPE => O::NEWTREE, O::X => 5000, O::Z => 5000, O::HEIGHT => 10000],
            [O::TYPE => O::RAINGARDEN, O::POINTS => [[-9000, -9000], [1000, -9000], [1000, 0], [-9000, 0]], O::STORAGE => 300],
            [O::TYPE => O::PERMEABLE, O::POINTS => [[0, 0], [10000, 0], [10000, 6000], [0, 6000]], O::KIND => O::KIND_PAVERS],
            [O::TYPE => O::SHADESAIL, O::POINTS => [[-10000, 4000], [-4000, 4000], [-4000, 9000], [-10000, 9000]], O::Y => 3200],
        ]);

        $report = (new ImpactCalculator())->calculate($site, self::STORM);

        self::assertSame([], $report->warnings);
        foreach ($report->metrics as $metric) {
            self::assertTrue($metric->toArray()['improved'], $metric->key.' did not improve: '.json_encode($metric->toArray()));
        }
        self::assertGreaterThan(0.0, $report->metric(ImpactCalculator::METRIC_RETENTION)?->delta());
    }

    #[Test]
    public function withoutInterventionsBaselineEqualsDesign(): void
    {
        $report = (new ImpactCalculator())->calculate($this->site([]), self::STORM);

        foreach ($report->metrics as $metric) {
            self::assertEqualsWithDelta($metric->baseline, $metric->design, 0.0001, $metric->key);
        }
        self::assertEqualsWithDelta(0.9, (float) $report->metric(ImpactCalculator::METRIC_PEAK_COEFFICIENT)?->baseline, 0.01);
    }

    #[Test]
    public function aLawnOnTheMapIsNoAsphaltTodayAndAnInterventionOnItWins(): void
    {
        $lawn = [O::TYPE => O::AREA, O::POINTS => [[0, 0], [15000, 0], [15000, 15000], [0, 15000]], O::USE => O::AREA_GREEN, O::SURFACE => O::SURFACE_LAWN];
        $asphalt = (new ImpactCalculator())->calculate($this->site([]), self::STORM);
        $withLawn = (new ImpactCalculator())->calculate($this->site([$lawn]), self::STORM);
        $rainGardenOnIt = (new ImpactCalculator())->calculate($this->site([$lawn, [O::TYPE => O::RAINGARDEN, O::POINTS => [[0, 0], [5000, 0], [5000, 5000], [0, 5000]]]]), self::STORM);

        // a quarter of the plot is lawn: it counts as permeable today, not as part of the plan
        self::assertGreaterThan(20.0, (float) $withLawn->metric(ImpactCalculator::METRIC_PERMEABLE_SHARE)?->baseline);
        self::assertSame(0.0, (float) $asphalt->metric(ImpactCalculator::METRIC_PERMEABLE_SHARE)?->baseline);
        self::assertEqualsWithDelta($withLawn->metric(ImpactCalculator::METRIC_PERMEABLE_SHARE)?->baseline, $withLawn->metric(ImpactCalculator::METRIC_PERMEABLE_SHARE)?->design, 0.0001);
        self::assertGreaterThan(20.0, $rainGardenOnIt->areasSquareMetres[GroundClass::RainGarden->value] ?? 0.0, 'the garden lies on the lawn, and counts');
    }

    #[Test]
    public function overlappingInterventionsAreNotCountedTwice(): void
    {
        $square = [[-5000, -5000], [5000, -5000], [5000, 5000], [-5000, 5000]];
        $once = (new ImpactCalculator())->calculate($this->site([[O::TYPE => O::PLANTING, O::POINTS => $square]]), self::STORM);
        $twice = (new ImpactCalculator())->calculate($this->site([[O::TYPE => O::PLANTING, O::POINTS => $square], [O::TYPE => O::PLANTING, O::POINTS => $square]]), self::STORM);

        self::assertEqualsWithDelta($once->metric(ImpactCalculator::METRIC_RETENTION)?->design, $twice->metric(ImpactCalculator::METRIC_RETENTION)?->design, 0.0001);
    }

    #[Test]
    public function aRainGardenWithTooMuchCatchmentWarns(): void
    {
        $report = (new ImpactCalculator())->calculate($this->site([
            [O::TYPE => O::RAINGARDEN, O::POINTS => [[0, 0], [2000, 0], [2000, 2000], [0, 2000]], O::STORAGE => 300],
        ]), self::STORM);

        self::assertNotSame([], $report->warnings);
    }

    #[Test]
    public function importsOpenStreetMapElementsAndEvaluatesTheScene(): void
    {
        $centre = new GeoPoint(self::CENTRE[0], self::CENTRE[1], 'Test school');
        $d = 0.0002;
        $elements = [
            ['type' => 'way', 'tags' => ['amenity' => 'school'], 'geometry' => [
                ['lat' => 51.0 - $d, 'lon' => 7.0 - $d], ['lat' => 51.0 - $d, 'lon' => 7.0 + $d], ['lat' => 51.0 + $d, 'lon' => 7.0 + $d], ['lat' => 51.0 + $d, 'lon' => 7.0 - $d], ['lat' => 51.0 - $d, 'lon' => 7.0 - $d],
            ]],
            ['type' => 'way', 'tags' => ['building' => 'school', 'building:levels' => '3'], 'geometry' => [
                ['lat' => 51.0003, 'lon' => 7.0], ['lat' => 51.0003, 'lon' => 7.0004], ['lat' => 51.0005, 'lon' => 7.0004], ['lat' => 51.0005, 'lon' => 7.0], ['lat' => 51.0003, 'lon' => 7.0],
            ]],
            ['type' => 'node', 'lat' => 51.0001, 'lon' => 7.0001, 'tags' => ['natural' => 'tree']],
        ];

        $import = (new SiteImporter())->import($centre, $elements);

        self::assertFalse($import->plotIsFallback);
        self::assertSame(1, $import->buildings);
        self::assertSame(1, $import->trees);
        self::assertGreaterThan(300.0, $import->plotSquareMetres);

        $evaluator = self::getContainer()->get(PlanEvaluator::class);
        $site = (new SiteType())->normalize($import->siteJson);
        $values = [SiteType::PARAMETER => $site];
        $evaluation = $evaluator->evaluate($values);
        $scene = (new SceneSerializer(new class() implements ModelCatalog {
            public function url(string $library): ?string
            {
                return null;
            }
        }))->serialize($evaluation->scene(), $evaluation->definition->materials(), $evaluation->setting());

        self::assertNotEmpty($scene['parts']);
        self::assertContains('SC-PLOT', array_column($scene['parts'], 'sku'));
    }

    /**
     * @param list<array<string, mixed>> $interventions
     */
    #[Test]
    public function aBuildingShadesTheGroundOnItsFarSideFromTheSunAsFarAsItsHeightReaches(): void
    {
        $plot = [O::TYPE => O::PLOT, O::POINTS => [[-15000, -15000], [15000, -15000], [15000, 15000], [-15000, 15000]], O::SURFACE => O::SURFACE_ASPHALT];
        // 10 m high along the plot's west side: the sun (south-west, 52° high) throws its shadow 7.8 m to the north-east
        $west = [O::TYPE => O::BUILDING, O::POINTS => [[-25000, -15000], [-15000, -15000], [-15000, 15000], [-25000, 15000]], O::HEIGHT => 10000];
        // as high along the east side: its shadow falls away from the plot
        $east = [O::TYPE => O::BUILDING, O::POINTS => [[15000, -15000], [25000, -15000], [25000, 15000], [15000, 15000]], O::HEIGHT => 10000];
        $shade = static fn (array $objects): float => (float) (new ImpactCalculator())
            ->calculate(Site::fromJson((new SiteType())->normalize(['objects' => [$plot, ...$objects]])), self::STORM)
            ->metric(ImpactCalculator::METRIC_SHADE)?->baseline;

        self::assertSame(0.0, $shade([]));
        // a band of about 5.5 m (7.8 m along the diagonal) of the 30 m plot, less the corner it misses
        self::assertEqualsWithDelta(16.0, $shade([$west]), 2.5);
        self::assertSame(0.0, $shade([$east]), 'its shadow lies outside the plot');
        self::assertGreaterThan($shade([$west]) + 1.0, $shade([$west, [O::TYPE => O::TREE, O::X => 5000, O::Z => 0, O::HEIGHT => 12000, O::DIAMETER => 8000]]), 'a tree crown adds its shadow');
    }

    private function site(array $interventions): Site
    {
        $plot = [O::TYPE => O::PLOT, O::POINTS => [[-15000, -15000], [15000, -15000], [15000, 15000], [-15000, 15000]], O::SURFACE => O::SURFACE_ASPHALT];
        $building = [O::TYPE => O::BUILDING, O::POINTS => [[-15000, -15000], [-9000, -15000], [-9000, -11000], [-15000, -11000]], O::HEIGHT => 9000];

        return Site::fromJson((new SiteType())->normalize(['objects' => [$plot, $building, ...$interventions]]));
    }
}

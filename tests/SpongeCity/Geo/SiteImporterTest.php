<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Geo;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\SiteImporter;
use Core\Configurator\Scenery\Building;
use Core\Configurator\Site\BuildingType;
use Core\Configurator\Site\Facade;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SiteImporterTest extends TestCase
{
    private const float LATITUDE = 53.55;
    private const float LONGITUDE = 10.02;

    /**
     * @return iterable<string, array{array<string, string>, BuildingType}>
     */
    public static function uses(): iterable
    {
        yield 'house' => [['building' => 'detached'], BuildingType::House];
        yield 'school' => [['building' => 'school'], BuildingType::School];
        yield 'warehouse' => [['building' => 'warehouse'], BuildingType::Industrial];
        yield 'tall apartments are a high-rise' => [['building' => 'apartments', 'building:levels' => '12'], BuildingType::HighRise];
        yield 'an untagged tall building is housing' => [['building' => 'yes', 'building:levels' => '5'], BuildingType::Apartments];
        yield 'an untagged low building stays unknown' => [['building' => 'yes', 'building:levels' => '1'], BuildingType::Other];
    }

    /**
     * @param array<string, string> $tags
     */
    #[Test]
    #[DataProvider('uses')]
    public function classifiesBuildingsByTheirFunction(array $tags, BuildingType $expected): void
    {
        $building = $this->importBuilding($tags);

        self::assertSame($expected->value, $building[O::USE]);
        self::assertContains(Facade::from($building[O::FACADE]), $expected->facades());
    }

    #[Test]
    public function tagsOnTheMapWinOverTheTypology(): void
    {
        $building = $this->importBuilding([
            'building' => 'office', 'building:material' => 'brick', 'building:colour' => 'Dark Red',
            'roof:shape' => 'hipped', 'roof:colour' => '#33AA55',
        ]);

        self::assertSame(Facade::Brick->value, $building[O::FACADE]);
        self::assertSame('#7b3a2e', $building[O::COLOR]);
        self::assertSame(Building::ROOF_HIP, $building[O::ROOF]);
        self::assertSame('#33aa55', $building[O::ROOF_COLOR]);
    }

    #[Test]
    public function withoutColoursAFacadePicksFromItsPaletteTheSameEveryTime(): void
    {
        $first = $this->importBuilding(['building' => 'house']);
        $again = $this->importBuilding(['building' => 'house']);

        self::assertContains($first[O::COLOR], Facade::from($first[O::FACADE])->colours());
        self::assertContains($first[O::ROOF_COLOR], BuildingType::roofColours($first[O::ROOF]));
        self::assertSame(Building::ROOF_GABLE, $first[O::ROOF]);
        self::assertSame($first, $again);
    }

    #[Test]
    public function aLongOutlineKeepsItsCornersAndLosesTheCurvesPoints(): void
    {
        // a 40 × 30 m U, open to the south, its 20 m wide courtyard closed by a curve of 41 points
        $u = [[0, 0], [40, 0], [40, 30], [30, 30], [30, 10]];
        for ($i = 0; $i <= 40; ++$i) {
            $u[] = [20 + 10 * cos(M_PI * $i / 40), 10 + 2 * sin(M_PI * $i / 40)];
        }
        array_push($u, [10, 10], [10, 30], [0, 30]);
        $m = 1 / 111_195;
        $ring = array_map(static fn (array $p): array => [-$p[1] * $m, $p[0] * $m / cos(deg2rad(self::LATITUDE))], $u);

        $building = $this->importBuilding(['building' => 'school'], $ring);

        self::assertLessThanOrEqual(O::MAX_POINTS, count($building[O::POINTS]));
        foreach ([[0, 0], [40000, 0], [40000, 30000], [30000, 30000], [10000, 30000], [0, 30000]] as $corner) {
            $nearest = min(array_map(static fn (array $p): float => hypot($p[0] - $corner[0], $p[1] - $corner[1]), $building[O::POINTS]));
            self::assertLessThan(50, $nearest, sprintf('the corner at %d/%d stays', $corner[0], $corner[1]));
        }
    }

    #[Test]
    public function aBuildingsModelArrivesInPlanMillimetres(): void
    {
        $m = 1 / 111_195;
        $geo = static fn (float $x, float $z, float $height): array => [self::LATITUDE - $z * $m, self::LONGITUDE + $x * $m / cos(deg2rad(self::LATITUDE)), $height];
        $element = ['model' => [
            'roofs' => [[$geo(11, 0, 9), $geo(22, 0, 9), $geo(22, 5, 12.5)]],
            'walls' => [[$geo(11, 0, 9), $geo(11, 5, 9), $geo(11, 5, 12.5)]],
        ]];

        $building = $this->importBuilding(['building' => 'house'], null, $element);

        self::assertEqualsWithDelta([11000, 9000, 0], $building[O::MODEL][O::MODEL_ROOFS][0][0], 30);
        self::assertEqualsWithDelta([22000, 12500, 5000], $building[O::MODEL][O::MODEL_ROOFS][0][2], 30);
        self::assertCount(1, $building[O::MODEL][O::MODEL_WALLS]);
    }

    #[Test]
    public function theGroundAreasOnTheSchoolyardComeWithTheirUseAndSurface(): void
    {
        // a schoolyard around the point; on it a car park, a pitch, a service area and lawn; a car park next door
        $square = static fn (float $south, float $west, float $size): array => array_map(
            static fn (array $p): array => ['lat' => self::LATITUDE + $p[0], 'lon' => self::LONGITUDE + $p[1]],
            [[$south, $west], [$south, $west + $size], [$south + $size, $west + $size], [$south + $size, $west], [$south, $west]],
        );
        $way = static fn (array $tags, array $geometry): array => ['type' => 'way', 'tags' => $tags, 'geometry' => $geometry];
        $import = (new SiteImporter())->import(new GeoPoint(self::LATITUDE, self::LONGITUDE, 'Test'), [
            $way(['amenity' => 'school', 'name' => 'Schule'], $square(-0.0005, -0.0008, 0.001)),
            $way(['amenity' => 'parking', 'surface' => 'paving_stones'], $square(-0.0004, -0.0007, 0.0002)),
            $way(['leisure' => 'pitch'], $square(0.0001, -0.0004, 0.0003)),
            $way(['highway' => 'service', 'area' => 'yes'], $square(-0.0004, 0.0001, 0.0002)),
            $way(['landuse' => 'grass'], $square(0.0002, -0.0006, 0.0002)),
            $way(['amenity' => 'parking'], $square(0.002, 0.002, 0.0002)),
        ]);

        /** @var array{objects: list<array<string, mixed>>} $site */
        $site = json_decode((new SiteType())->normalize($import->siteJson), true, flags: \JSON_THROW_ON_ERROR);
        $areas = array_values(array_filter($site['objects'], static fn (array $o): bool => O::AREA === $o[O::TYPE]));
        $uses = array_combine(array_column($areas, O::USE), array_column($areas, O::SURFACE));
        ksort($uses);

        self::assertSame([O::AREA_ACCESS => O::SURFACE_ASPHALT, O::AREA_GREEN => O::SURFACE_LAWN, O::AREA_PARKING => O::SURFACE_CONCRETE, O::AREA_SPORT => O::SURFACE_ASPHALT], $uses, 'the car park next door is not on the plot');
        self::assertSame([], array_values(array_filter($site['objects'], static fn (array $o): bool => O::ROAD === $o[O::TYPE])), 'a service area is no street along its outline');
        self::assertSame('Schule', array_values(array_filter($site['objects'], static fn (array $o): bool => O::PLOT === $o[O::TYPE]))[0][O::LABEL]);
    }

    #[Test]
    public function playEquipmentOnThePlotIsImportedWithItsFallZone(): void
    {
        $square = static fn (float $south, float $west, float $size): array => array_map(
            static fn (array $p): array => ['lat' => self::LATITUDE + $p[0], 'lon' => self::LONGITUDE + $p[1]],
            [[$south, $west], [$south, $west + $size], [$south + $size, $west + $size], [$south + $size, $west], [$south, $west]],
        );
        $node = static fn (array $tags, float $north, float $east): array => ['type' => 'node', 'tags' => $tags, 'lat' => self::LATITUDE + $north, 'lon' => self::LONGITUDE + $east];
        $import = (new SiteImporter())->import(new GeoPoint(self::LATITUDE, self::LONGITUDE, 'Test'), [
            ['type' => 'way', 'tags' => ['amenity' => 'school'], 'geometry' => $square(-0.0005, -0.0008, 0.001)],
            $node(['playground' => 'swing'], 0.0001, 0.0001),
            $node(['playground' => 'sandpit'], -0.0001, 0.0001),
            $node(['natural' => 'tree'], 0.0002, -0.0001),
            // the neighbour's slide
            $node(['playground' => 'slide'], 0.003, 0.003),
        ]);

        /** @var array{objects: list<array<string, mixed>>} $site */
        $site = json_decode((new SiteType())->normalize($import->siteJson), true, flags: \JSON_THROW_ON_ERROR);
        $equipment = array_values(array_filter($site['objects'], static fn (array $o): bool => O::EQUIPMENT === $o[O::TYPE]));

        self::assertSame([O::EQUIPMENT_SWING, O::EQUIPMENT_OTHER], array_column($equipment, O::KIND), 'unknown kinds are "other"; the neighbour\'s is left out');
        self::assertSame(O::EQUIPMENT_SIZES[O::EQUIPMENT_SWING][0], $equipment[0][O::WIDTH]);
        self::assertSame(O::LOOK_DATA, $equipment[0][O::LOOK]);
        self::assertCount(1, array_filter($site['objects'], static fn (array $o): bool => O::TREE === $o[O::TYPE]), 'equipment is no tree');
    }

    /**
     * @return array<string, mixed> the imported building, normalised
     */
    #[Test]
    public function thePlotsGroundIsUnknownUntilSomebodyDescribesIt(): void
    {
        $import = (new SiteImporter())->import(new GeoPoint(self::LATITUDE, self::LONGITUDE, 'Test'), []);
        /** @var array{objects: list<array<string, mixed>>} $site */
        $site = json_decode((new SiteType())->normalize($import->siteJson), true, flags: \JSON_THROW_ON_ERROR);
        $plots = array_values(array_filter($site['objects'], static fn (array $o): bool => O::PLOT === $o[O::TYPE]));

        self::assertCount(1, $plots);
        // the map shows its outline, not its surface: taking it for asphalt made every plan look sealed and hot
        self::assertSame(O::SURFACE_UNKNOWN, $plots[0][O::SURFACE], 'kept by the site schema, not reset to asphalt');
    }

    private function importBuilding(array $tags, ?array $ring = null, array $element = []): array
    {
        $d = 0.0001;
        $ring ??= [[$d, 0], [$d, $d], [2 * $d, $d], [2 * $d, 0], [$d, 0]];
        $geometry = array_map(static fn (array $p): array => ['lat' => self::LATITUDE + $p[0], 'lon' => self::LONGITUDE + $p[1]], $ring);
        $import = (new SiteImporter())->import(
            new GeoPoint(self::LATITUDE, self::LONGITUDE, 'Test'),
            [['type' => 'way', 'tags' => $tags, 'geometry' => $geometry, ...$element]],
        );
        /** @var array{objects: list<array<string, mixed>>} $site */
        $site = json_decode((new SiteType())->normalize($import->siteJson), true, flags: \JSON_THROW_ON_ERROR);
        $buildings = array_values(array_filter($site['objects'], static fn (array $o): bool => O::BUILDING === $o[O::TYPE]));
        self::assertCount(1, $buildings);

        return $buildings[0];
    }
}

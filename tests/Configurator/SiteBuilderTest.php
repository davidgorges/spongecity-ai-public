<?php

declare(strict_types=1);

namespace App\Tests\Configurator;

use Core\Configurator\Assembly\Extrusion;
use Core\Configurator\Assembly\Part;
use Core\Configurator\Scene\ProceduralTexture;
use Core\Configurator\Scenery\Building;
use Core\Configurator\Scenery\Slab;
use Core\Configurator\Site\Facade;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteBuilder;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SiteBuilderTest extends TestCase
{
    private const int HEIGHT = 12000;

    #[Test]
    public function aFootprintGetsFacadeWallsAndARoofSlabOnTop(): void
    {
        $assembly = (new SiteBuilder())->build($this->site([O::FACADE => Facade::Brick->value, O::COLOR => '#a4553c', O::ROOF => Building::ROOF_FLAT]));

        [$walls, $roof] = $assembly->parts();
        $materials = $assembly->materials();
        self::assertInstanceOf(Extrusion::class, $walls->shape);
        self::assertSame(ProceduralTexture::FacadeBrick, $materials[$walls->material]->texture);
        self::assertSame(Facade::BAY * Facade::BAYS_PER_REPEAT, $materials[$walls->material]->textureSize);
        self::assertSame(Facade::NIGHT_GLOW, $materials[$walls->material]->nightGlow);
        self::assertSame('#a4553c', $materials[$walls->material]->color);
        self::assertSame(ProceduralTexture::RoofGravel, $materials[$roof->material]->texture);
        // the roof slab starts where the walls end
        self::assertInstanceOf(Extrusion::class, $roof->shape);
        self::assertEqualsWithDelta(self::HEIGHT, $roof->position->y - $roof->shape->depth / 2, 0.001);
    }

    #[Test]
    public function aRectangularFootprintGetsAGableRoofOverItsLongSide(): void
    {
        $assembly = (new SiteBuilder())->build($this->site([O::ROOF => Building::ROOF_GABLE]));

        [$walls, $front, $back, $gableEast, $gableWest] = $assembly->parts();
        $materials = $assembly->materials();
        self::assertSame(ProceduralTexture::RoofTiles, $materials[$front->material]->texture);
        self::assertSame(ProceduralTexture::RoofTiles, $materials[$back->material]->texture);
        self::assertSame($walls->material, $gableEast->material);
        self::assertSame($walls->material, $gableWest->material);
        self::assertCount(5, $assembly->parts());
    }

    #[Test]
    public function anLShapedFootprintKeepsAFlatTiledRoof(): void
    {
        $lShape = [[0, 0], [10000, 0], [10000, 3000], [3000, 3000], [3000, 10000], [0, 10000]];
        $assembly = (new SiteBuilder())->build($this->site([O::ROOF => Building::ROOF_GABLE, O::POINTS => $lShape]));

        self::assertCount(2, $assembly->parts());
        self::assertSame(ProceduralTexture::RoofTiles, $assembly->materials()[$assembly->parts()[1]->material]->texture);
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    public function drawsOneRowOfWindowsPerStorey(): void
    {
        // 25 m in 5 storeys (5 m each): a ground floor a quarter taller, at most 5.5 m, the four above share the rest
        $assembly = (new SiteBuilder())->build($this->site([O::FACADE => Facade::Ribbon->value, O::ROOF => Building::ROOF_FLAT, O::HEIGHT => 25000, O::STOREYS => 5]));
        $walls = array_values(array_filter($assembly->materials(), static fn ($m): bool => null !== $m->textureHeight));

        self::assertSame([5.5 * 4, (25 - 5.5) / 4 * 4], array_map(static fn ($m): ?float => $m->textureHeight, $walls), 'one row a storey: the repeat holds four rows');
        self::assertSame(12.0, $walls[0]->textureSize, 'the windows keep their width');
        $pieces = array_values(array_filter($assembly->parts(), static fn ($p): bool => str_contains((string) $p->material, 'facade')));
        self::assertCount(2, $pieces, 'the ground floor and the storeys above, stacked');
        self::assertNull(Facade::Ribbon->textureHeight(25, 0), 'unknown storeys keep the square repeat');
        self::assertSame(2.6 * 4, Facade::Plaster->textureHeight(5, 5), 'a storey is at least 2.6 m');
    }

    #[Test]
    public function aGableIsDrawnAtTheBuildingsOwnPitch(): void
    {
        $steep = (new SiteBuilder())->build($this->site([O::ROOF => Building::ROOF_GABLE, O::PITCH => 50]))->parts();
        $usual = (new SiteBuilder())->build($this->site([O::ROOF => Building::ROOF_GABLE]))->parts();

        // the ridge of a gable over the 8 m width: 4 m × tan(pitch) above the eaves
        self::assertGreaterThan(self::top($usual[1]) + 1000, self::top($steep[1]));
        self::assertEqualsWithDelta(self::HEIGHT + 4000 * tan(deg2rad(50)), self::top($steep[1]) - 160 * cos(deg2rad(50)), 1, 'the slab lies on the plane, its upper face a thickness above');
    }

    #[Test]
    public function aBuildingWithItsOwnModelGetsTheModelsRoofAndGables(): void
    {
        // a monopitch roof the parametric roof cannot draw, with a flat terrace and the triangular wall at its end
        $model = [
            O::MODEL_ROOFS => [
                [[0, self::HEIGHT, 0], [10000, self::HEIGHT, 0], [10000, 15000, 6000], [0, 15000, 6000]],
                [[0, self::HEIGHT, 6000], [10000, self::HEIGHT, 6000], [10000, self::HEIGHT, 8000], [0, self::HEIGHT, 8000]],
            ],
            O::MODEL_WALLS => [[[0, self::HEIGHT, 0], [0, self::HEIGHT, 6000], [0, 15000, 6000]]],
        ];
        $site = $this->site([O::ROOF => Building::ROOF_MONOPITCH, O::COLOR => '#a4553c', O::MODEL => $model]);
        self::assertCount(2, $site->objects[0][O::MODEL][O::MODEL_ROOFS], 'the model survives normalising');

        $assembly = (new SiteBuilder())->build($site);
        [$walls, $slope, $terrace, $gable] = $assembly->parts();
        $materials = $assembly->materials();

        self::assertCount(4, $assembly->parts(), 'the façade, the two roof surfaces and the gable; no roof over the rectangle');
        self::assertNotNull($materials[$walls->material]->nightGlow, 'the façade with its windows up to the eaves');
        self::assertSame(ProceduralTexture::RoofTiles, $materials[$slope->material]->texture);
        self::assertSame(ProceduralTexture::RoofGravel, $materials[$terrace->material]->texture, 'flat surfaces are gravel');
        self::assertSame(ProceduralTexture::Plaster, $materials[$gable->material]->texture, 'a gable is plain wall in the façade colour');
        self::assertSame('#a4553c', $materials[$gable->material]->color);
        self::assertEqualsWithDelta(15000, self::top($slope) - 160 * cos(atan(3000 / 6000)), 1, 'the slope rises to the model\'s 15 m');
    }

    #[Test]
    public function aSlabLiesInItsPolygonsPlaneEvenWhenItsFirstCornersAreNearlyInLine(): void
    {
        // a roof surface rising 6 m over 12 m whose third corner lies 1 cm off the line through the first two
        $points = [[0.0, 10000.0, 0.0], [10000.0, 10000.0, 0.0], [20000.0, 10000.0, 10.0], [20000.0, 16000.0, 12000.0], [0.0, 16000.0, 12000.0]];
        $slab = Slab::planar('roof', 'tiles', $points, 2.0);

        self::assertNotNull($slab);
        foreach (self::corners($slab) as $i => $corner) {
            self::assertEqualsWithDelta($points[$i][1], $corner[1], 5, 'each corner at its height');
        }
    }

    /** The highest point of a slab's upper face. */
    private static function top(Part $part): float
    {
        return max(array_column(self::corners($part), 1));
    }

    /**
     * The corners of an extrusion's upper face in the plan, as the browser places them (Euler XYZ).
     *
     * @return list<array{float, float, float}>
     */
    private static function corners(Part $part): array
    {
        self::assertInstanceOf(Extrusion::class, $part->shape);
        [$a, $b, $c] = [$part->rotation->x, $part->rotation->y, $part->rotation->z];
        $rx = [[1, 0, 0], [0, cos($a), -sin($a)], [0, sin($a), cos($a)]];
        $ry = [[cos($b), 0, sin($b)], [0, 1, 0], [-sin($b), 0, cos($b)]];
        $rz = [[cos($c), -sin($c), 0], [sin($c), cos($c), 0], [0, 0, 1]];
        $multiply = static fn (array $m, array $n): array => array_map(static fn (array $row): array => array_map(static fn (int $j): float => $row[0] * $n[0][$j] + $row[1] * $n[1][$j] + $row[2] * $n[2][$j], [0, 1, 2]), $m);
        $r = $multiply($multiply($rx, $ry), $rz);

        return array_map(static function (array $p) use ($r, $part): array {
            $local = [$p[0], $p[1], $part->shape->depth / 2];

            return [
                $r[0][0] * $local[0] + $r[0][1] * $local[1] + $r[0][2] * $local[2] + $part->position->x,
                $r[1][0] * $local[0] + $r[1][1] * $local[1] + $r[1][2] * $local[2] + $part->position->y,
                $r[2][0] * $local[0] + $r[2][1] * $local[1] + $r[2][2] * $local[2] + $part->position->z,
            ];
        }, $part->shape->profile);
    }

    #[Test]
    public function aLowerRoofLevelsWallsStandBehindTheTallerOnesWhereTheyShareAFacade(): void
    {
        // the main block, and a top storey on its southern half, flush with its south, east and west walls
        $main = [[0, 0], [10000, 0], [10000, 8000], [0, 8000]];
        $top = [[0, 0], [10000, 0], [10000, 4000], [0, 4000]];
        $assembly = (new SiteBuilder())->build($this->site([O::ROOF => Building::ROOF_FLAT, O::HEIGHT => 15000, O::PARTS => [
            [O::POINTS => $main, O::HEIGHT => 9000],
            [O::POINTS => $top, O::HEIGHT => 15000],
        ]]));

        $walls = array_values(array_filter($assembly->parts(), static fn ($p): bool => str_contains((string) $p->material, 'facade')));
        $byHeight = [];
        foreach ($walls as $wall) {
            self::assertInstanceOf(Extrusion::class, $wall->shape);
            $byHeight[(int) $wall->shape->depth] = $wall->shape->profile;
        }
        $xs = static fn (array $profile): array => [min(array_column($profile, 0)), max(array_column($profile, 0))];
        // the taller level on its own outline; the lower one 3 cm inside it, behind its walls
        self::assertEqualsWithDelta([0.0, 10000.0], $xs($byHeight[15000]), 0.5);
        self::assertEqualsWithDelta([30.0, 9970.0], $xs($byHeight[9000]), 0.5);
    }

    private function site(array $fields): Site
    {
        $building = [O::TYPE => O::BUILDING, O::POINTS => [[0, 0], [10000, 0], [10000, 8000], [0, 8000]], O::HEIGHT => self::HEIGHT, ...$fields];

        return Site::fromJson((new SiteType())->normalize(['objects' => [$building]]));
    }
}

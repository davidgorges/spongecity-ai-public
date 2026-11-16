<?php

declare(strict_types=1);

namespace App\Tests\Configurator;

use Core\Configurator\Assembly\Extrusion;
use Core\Configurator\Scene\ProceduralTexture;
use Core\Configurator\Scenery\Building;
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
    private function site(array $fields): Site
    {
        $building = [O::TYPE => O::BUILDING, O::POINTS => [[0, 0], [10000, 0], [10000, 8000], [0, 8000]], O::HEIGHT => self::HEIGHT, ...$fields];

        return Site::fromJson((new SiteType())->normalize(['objects' => [$building]]));
    }
}

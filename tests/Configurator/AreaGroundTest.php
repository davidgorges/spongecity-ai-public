<?php

declare(strict_types=1);

namespace App\Tests\Configurator;

use Core\Configurator\Assembly\Extrusion;
use Core\Configurator\Assembly\Part;
use Core\Configurator\Product\SpongeCity\InterventionBuilder;
use Core\Configurator\Product\SpongeCity\SpongeCitySku;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The view shows the place as it looks: a lawn on an asphalt plot is drawn as lawn, not as the
 * plot's asphalt. An area of unknown ground, or of the plot's own, adds nothing; none is billed.
 */
final class AreaGroundTest extends TestCase
{
    private const array PLOT = [[-20000, -20000], [20000, -20000], [20000, 20000], [-20000, 20000]];
    private const array LAWN = [[-10000, -10000], [0, -10000], [0, 0], [-10000, 0]];
    private const array YARD = [[1000, 1000], [9000, 1000], [9000, 9000], [1000, 9000]];

    #[Test]
    public function aLawnOnAnAsphaltPlotIsDrawnAsLawn(): void
    {
        $assembly = (new InterventionBuilder())->build($this->site([
            [O::TYPE => O::AREA, O::USE => O::AREA_GREEN, O::SURFACE => O::SURFACE_LAWN, O::POINTS => self::LAWN],
        ]));

        $areas = $this->ofSku($assembly->parts(), SpongeCitySku::AREA);
        self::assertCount(1, $areas);
        $material = $areas[0]->material;
        self::assertSame(InterventionBuilder::MATERIAL_PLOT_PREFIX.O::SURFACE_LAWN, $material);
        self::assertNotSame($assembly->materials()[InterventionBuilder::MATERIAL_PLOT_PREFIX.O::SURFACE_ASPHALT]->color, $assembly->materials()[$material]->color);
        self::assertFalse($areas[0]->counted, 'ground as it is, not an article');
        self::assertTrue($areas[0]->draped, 'on the terrain, like the plot');
    }

    #[Test]
    public function anAreaOfUnknownGroundOrThePlotsOwnAddsNothing(): void
    {
        $assembly = (new InterventionBuilder())->build($this->site([
            [O::TYPE => O::AREA, O::USE => O::AREA_YARD, O::SURFACE => O::SURFACE_UNKNOWN, O::POINTS => self::LAWN],
            [O::TYPE => O::AREA, O::USE => O::AREA_PARKING, O::SURFACE => O::SURFACE_ASPHALT, O::POINTS => self::YARD],
        ]));

        self::assertSame([], $this->ofSku($assembly->parts(), SpongeCitySku::AREA));
        self::assertCount(1, $this->ofSku($assembly->parts(), SpongeCitySku::PLOT), 'the plot stays one part, the one the plot map reshapes');
    }

    #[Test]
    public function aLawnLiesBetweenThePlotAndWhatIsPlannedOnIt(): void
    {
        // the depth test keeps them apart in this order (ground_layers.js): it must be their order in height too
        $parts = (new InterventionBuilder())->build($this->site([
            [O::TYPE => O::AREA, O::USE => O::AREA_GREEN, O::SURFACE => O::SURFACE_LAWN, O::POINTS => self::LAWN],
            [O::TYPE => O::PLANTING, O::POINTS => [[-8000, -8000], [-4000, -8000], [-4000, -4000], [-8000, -4000]]],
            [O::TYPE => O::COOLSURFACE, O::POINTS => self::YARD],
        ]))->parts();

        $plot = self::top($this->ofSku($parts, SpongeCitySku::PLOT)[0]);
        $area = self::top($this->ofSku($parts, SpongeCitySku::AREA)[0]);
        self::assertGreaterThan($plot, $area);
        foreach ([SpongeCitySku::PLANTING, SpongeCitySku::COOL_SURFACE] as $sku) {
            self::assertGreaterThan($area, self::top($this->ofSku($parts, $sku)[0]), $sku.' lies on the lawn');
        }
    }

    #[Test]
    public function theViewKnowsTheGroundLayersByTheirSkus(): void
    {
        $layers = (string) file_get_contents(\dirname(__DIR__, 2).'/assets/configurator/ground_layers.js');

        self::assertStringContainsString(sprintf("SKU_PLOT = '%s'", SpongeCitySku::PLOT), $layers);
        self::assertStringContainsString(sprintf("SKU_AREA = '%s'", SpongeCitySku::AREA), $layers);
    }

    /** millimetres: the top of a slab lying on the ground */
    private static function top(Part $part): float
    {
        \assert($part->shape instanceof Extrusion);

        return $part->position->y + $part->shape->depth / 2;
    }

    /**
     * @param list<array<string, mixed>> $areas
     */
    private function site(array $areas): Site
    {
        return Site::fromJson((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
            ...$areas,
        ]]));
    }

    /**
     * @param list<Part> $parts
     *
     * @return list<Part>
     */
    private function ofSku(array $parts, string $sku): array
    {
        return array_values(array_filter($parts, static fn (Part $part): bool => $sku === $part->sku));
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Geo;

use App\SpongeCity\Geo\RoofFit;
use Core\Configurator\Scenery\Building;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which pitched roofs are drawn as a gable or hip at the model's pitch, and which as the model's own surfaces.
 */
final class RoofFitTest extends TestCase
{
    private const float EAVES = 9.0;
    private const float PITCH = 35.0;

    #[Test]
    public function aPlainGableIsDrawnParametricallyAtTheModelsPitch(): void
    {
        $choice = RoofFit::choose(self::rectangle(20, 10), self::gable(20, 10), []);

        self::assertNotNull($choice);
        self::assertSame(Building::ROOF_GABLE, $choice[RoofFit::RESULT_ROOF]);
        self::assertEqualsWithDelta(self::PITCH, $choice[RoofFit::RESULT_PITCH], 0.1);
        self::assertEqualsWithDelta(self::EAVES, $choice[RoofFit::RESULT_EAVES], 0.001);
        self::assertArrayNotHasKey(RoofFit::RESULT_ROOFS, $choice);
    }

    #[Test]
    public function aPlainHipIsDrawnParametrically(): void
    {
        $choice = RoofFit::choose(self::rectangle(20, 10), self::hip(20, 10), []);

        self::assertSame(Building::ROOF_HIP, $choice[RoofFit::RESULT_ROOF] ?? null);
    }

    #[Test]
    public function aGableWithATowerKeepsTheModelsSurfaces(): void
    {
        // a stair tower 3 × 3 m rising to 15 m out of the front of the roof
        $tower = ['points' => [[8.5, 1.0], [11.5, 1.0], [11.5, 4.0], [8.5, 4.0]], 'heights' => [15.0, 15.0, 15.0, 15.0]];
        $walls = [
            // the gable end at x = 0, from the ground to the ridge
            ['points' => [[0.0, 0.0], [0.0, 10.0], [0.0, 10.0], [0.0, 5.0], [0.0, 0.0]], 'heights' => [0.0, 0.0, self::EAVES, self::ridge(10), self::EAVES]],
        ];

        $choice = RoofFit::choose(self::rectangle(20, 10), [...self::gable(20, 10), $tower], $walls);

        self::assertNotNull($choice);
        self::assertArrayNotHasKey(RoofFit::RESULT_ROOF, $choice);
        self::assertCount(3, $choice[RoofFit::RESULT_ROOFS] ?? []);
        // the wall only above the eaves: the gable triangle, the façade below is drawn from the outline
        self::assertCount(1, $choice[RoofFit::RESULT_WALLS] ?? []);
        self::assertGreaterThanOrEqual(self::EAVES, min(array_column($choice[RoofFit::RESULT_WALLS][0], 1)));
        self::assertEqualsWithDelta(self::ridge(10), max(array_column($choice[RoofFit::RESULT_WALLS][0], 1)), 0.001);
    }

    #[Test]
    public function anLShapedBuildingKeepsTheModelsSurfaces(): void
    {
        $outline = [[0.0, 0.0], [20.0, 0.0], [20.0, 10.0], [10.0, 10.0], [10.0, 20.0], [0.0, 20.0]];

        $choice = RoofFit::choose($outline, self::gable(20, 10), []);

        self::assertNotNull($choice);
        self::assertArrayNotHasKey(RoofFit::RESULT_ROOF, $choice);
        self::assertCount(2, $choice[RoofFit::RESULT_ROOFS] ?? []);
    }

    #[Test]
    public function aDetailedModelIsDrawnWhileTheFitAndEavesComeFromTheGeneralisedOne(): void
    {
        $outline = [[0.0, 0.0], [20.0, 0.0], [20.0, 10.0], [10.0, 10.0], [10.0, 20.0], [0.0, 20.0]];
        // the detailed model adds a dormer and a canopy at 3 m, below the eaves
        $dormer = ['points' => [[5.0, 1.0], [7.0, 1.0], [7.0, 2.0], [5.0, 2.0]], 'heights' => [11.0, 11.0, 11.0, 11.0]];
        $canopy = ['points' => [[0.0, -3.0], [4.0, -3.0], [4.0, 0.0], [0.0, 0.0]], 'heights' => [3.0, 3.0, 3.0, 3.0]];
        $wall = ['points' => [[5.0, 1.0], [7.0, 1.0], [7.0, 1.0], [5.0, 1.0]], 'heights' => [self::EAVES + 0.5, self::EAVES + 0.5, 11.0, 11.0]];

        $choice = RoofFit::choose($outline, self::gable(20, 10), [], [...self::gable(20, 10), $dormer, $canopy], [$wall]);

        self::assertEqualsWithDelta(self::EAVES, $choice[RoofFit::RESULT_EAVES] ?? null, 0.001, 'the canopy does not lower the façade');
        self::assertCount(4, $choice[RoofFit::RESULT_ROOFS] ?? []);
        self::assertCount(1, $choice[RoofFit::RESULT_WALLS] ?? []);
    }

    #[Test]
    public function withoutRoofSurfacesThereIsNothingToChoose(): void
    {
        self::assertNull(RoofFit::choose(self::rectangle(20, 10), [], []));
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private static function rectangle(float $length, float $width): array
    {
        return [[0.0, 0.0], [$length, 0.0], [$length, $width], [0.0, $width]];
    }

    private static function ridge(float $width): float
    {
        return self::EAVES + $width / 2 * tan(deg2rad(self::PITCH));
    }

    /**
     * A gable roof along x: two planes meeting at the ridge in the middle of the width.
     *
     * @return list<array{points: list<array{0: float, 1: float}>, heights: list<float>}>
     */
    private static function gable(float $length, float $width): array
    {
        [$e, $r, $m] = [self::EAVES, self::ridge($width), $width / 2];

        return [
            ['points' => [[0.0, 0.0], [$length, 0.0], [$length, $m], [0.0, $m]], 'heights' => [$e, $e, $r, $r]],
            ['points' => [[0.0, $m], [$length, $m], [$length, $width], [0.0, $width]], 'heights' => [$r, $r, $e, $e]],
        ];
    }

    /**
     * A hip roof along x: two trapezoids and two triangles, all at the same pitch.
     *
     * @return list<array{points: list<array{0: float, 1: float}>, heights: list<float>}>
     */
    private static function hip(float $length, float $width): array
    {
        [$e, $r, $m] = [self::EAVES, self::ridge($width), $width / 2];

        return [
            ['points' => [[0.0, 0.0], [$length, 0.0], [$length - $m, $m], [$m, $m]], 'heights' => [$e, $e, $r, $r]],
            ['points' => [[$length, $width], [0.0, $width], [$m, $m], [$length - $m, $m]], 'heights' => [$e, $e, $r, $r]],
            ['points' => [[0.0, $width], [0.0, 0.0], [$m, $m]], 'heights' => [$e, $e, $r]],
            ['points' => [[$length, 0.0], [$length, $width], [$length - $m, $m]], 'heights' => [$e, $e, $r]],
        ];
    }
}

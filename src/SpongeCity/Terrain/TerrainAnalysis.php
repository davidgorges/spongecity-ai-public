<?php

declare(strict_types=1);

namespace App\SpongeCity\Terrain;

use Core\Configurator\Product\SpongeCity\Impact\Polygon;

/**
 * What the terrain says about a plot, for the agent: how high it lies, how steep it is,
 * which way surface water runs, and where it collects (low spots: candidate places for
 * rain gardens). Read on the fine grid's nodes inside the plot.
 *
 * Planning level only: the model is bare earth at 2 m; kerbs, gutters and drains, which
 * decide where water really goes on a paved yard, are below its resolution.
 */
final readonly class TerrainAnalysis
{
    /** a low spot is lower than the ring this far around it, by at least MIN_DEPTH */
    private const float RING_METRES = 8.0;
    private const float MIN_DEPTH_METRES = 0.05;
    private const int MAX_LOW_SPOTS = 5;
    /** low spots closer than this are one */
    private const float LOW_SPOT_SPACING_METRES = 10.0;
    private const int RING_SAMPLES = 12;
    /** deeper than this, a hollow on a city plot is mostly built: a ramp, a stairwell, a light well */
    private const float BUILT_DEPTH_METRES = 1.0;
    public const string KIND_HOLLOW = 'hollow';
    public const string KIND_BUILT = 'probably built (ramp, stairwell or light well): check before using';
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const array DIRECTIONS = ['east', 'south-east', 'south', 'south-west', 'west', 'north-west', 'north', 'north-east'];

    /**
     * @param list<array{0: float, 1: float}>       $plot      outline in plan millimetres
     * @param list<list<array{0: float, 1: float}>> $buildings footprints in plan millimetres: the open ground is what counts
     *                                                         (under a building the model may hold a ramp or a light well)
     *
     * @return array<string, mixed>|null null when no grid node lies on the plot's open ground
     */
    public function ofPlot(Terrain $terrain, array $plot, array $buildings = []): ?array
    {
        $grid = $terrain->fine;
        $metres = static fn (array $outline): array => array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], $outline);
        $outline = $metres($plot);
        $footprints = array_map($metres, $buildings);
        $nodes = [];
        for ($r = 1; $r < $grid->rows - 1; ++$r) {
            for ($c = 1; $c < $grid->columns - 1; ++$c) {
                [$x, $z] = [$grid->originX + $c * $grid->spacing, $grid->originZ + $r * $grid->spacing];
                if (Polygon::contains($outline, $x, $z) && [] === array_filter($footprints, static fn (array $f): bool => Polygon::contains($f, $x, $z))) {
                    $nodes[] = [$c, $r, $x, $z, $grid->node($c, $r)];
                }
            }
        }
        if ([] === $nodes) {
            return null;
        }

        // slope from central differences; the mean gradient says which way water runs
        $slopes = [];
        [$gx, $gz] = [0.0, 0.0];
        foreach ($nodes as [$c, $r]) {
            $dx = ($grid->node($c + 1, $r) - $grid->node($c - 1, $r)) / (2 * $grid->spacing);
            $dz = ($grid->node($c, $r + 1) - $grid->node($c, $r - 1)) / (2 * $grid->spacing);
            $slopes[] = hypot($dx, $dz);
            [$gx, $gz] = [$gx + $dx, $gz + $dz];
        }
        $heights = array_column($nodes, 4);
        // downhill is against the gradient; x east, z south: angle 0 = east, 90° = south
        $angle = rad2deg(atan2(-$gz, -$gx));
        $direction = self::DIRECTIONS[((int) round(($angle < 0 ? $angle + 360 : $angle) / 45)) % 8];
        $meanSlope = array_sum($slopes) / count($slopes);
        $lowest = $nodes[array_search(min($heights), $heights, true)];

        return [
            'elevation_m_above_sea_level' => ['min' => round($terrain->reference + min($heights), 2), 'max' => round($terrain->reference + max($heights), 2)],
            'relief_m' => round(max($heights) - min($heights), 2),
            'mean_slope_percent' => round($meanSlope * 100, 1),
            'surface_water_runs_towards' => hypot($gx, $gz) / count($nodes) < 0.002 ? 'nowhere in particular (flat)' : $direction,
            'lowest_point_mm' => [(int) round($lowest[2] * self::MILLIMETRES_PER_METRE), (int) round($lowest[3] * self::MILLIMETRES_PER_METRE)],
            'low_spots' => $this->lowSpots($terrain, $nodes),
            'note' => 'From the DGM1 terrain model at 2 m: shows the lie of the land, not kerbs, gutters or drains, which on a paved yard decide where water goes. Low spots are where runoff collects: good candidates for rain gardens, to be confirmed on site; deep, narrow ones are usually built (ramps, stairwells).',
        ];
    }

    /**
     * @param list<array{int, int, float, float, float}> $nodes
     *
     * @return list<array{x: int, z: int, depth_cm: int, elevation_m_above_sea_level: float, kind: string}>
     */
    private function lowSpots(Terrain $terrain, array $nodes): array
    {
        $candidates = [];
        foreach ($nodes as [, , $x, $z, $h]) {
            $ring = 0.0;
            for ($k = 0; $k < self::RING_SAMPLES; ++$k) {
                $a = 2 * M_PI * $k / self::RING_SAMPLES;
                $ring += $terrain->heightAt($x + cos($a) * self::RING_METRES, $z + sin($a) * self::RING_METRES);
            }
            $depth = $ring / self::RING_SAMPLES - $h;
            if ($depth >= self::MIN_DEPTH_METRES) {
                $candidates[] = [$x, $z, $depth, $h];
            }
        }
        usort($candidates, static fn (array $a, array $b): int => $b[2] <=> $a[2]);
        $spots = [];
        foreach ($candidates as [$x, $z, $depth, $h]) {
            foreach ($spots as $spot) {
                if (hypot($spot[0] - $x, $spot[1] - $z) < self::LOW_SPOT_SPACING_METRES) {
                    continue 2;
                }
            }
            $spots[] = [$x, $z, $depth, $h];
            if (count($spots) >= self::MAX_LOW_SPOTS) {
                break;
            }
        }

        return array_map(static fn (array $s): array => [
            'x' => (int) round($s[0] * self::MILLIMETRES_PER_METRE),
            'z' => (int) round($s[1] * self::MILLIMETRES_PER_METRE),
            'depth_cm' => (int) round($s[2] * 100),
            'elevation_m_above_sea_level' => round($terrain->reference + $s[3], 2),
            'kind' => $s[2] >= self::BUILT_DEPTH_METRES ? self::KIND_BUILT : self::KIND_HOLLOW,
        ], $spots);
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Scenery\Building;
use Core\Configurator\Site\PitchedRoof;

/**
 * Chooses how a pitched roof is drawn, from a building's 3D model (Hamburg's LoD2):
 *
 * - **parametric** (PitchedRoof: a gable or hip over the outline's rectangle) when it is
 *   close enough to the model: its pitch and heights taken from the model, then compared
 *   point by point with the model's roof surfaces. Cheap, editable, and most of the city's
 *   pitched roofs are such plain roofs;
 * - **the model's own surfaces** otherwise (L-shapes, half-hips, mansards, towers, dormers,
 *   a mix of roofs): its roof surfaces, and the parts of its walls above the eaves (gables,
 *   the walls between roof levels). Below the eaves the outline's façade is drawn as before.
 *
 * Plan metres; heights above the building's lowest ground point.
 *
 * @phpstan-type Ring array{points: list<array{0: float, 1: float}>, heights: list<float>}
 * @phpstan-type Point3 array{0: float, 1: float, 2: float} x, height, z
 */
final class RoofFit
{
    /** the outline must fill this much of its rectangle for a single roof over it (the drawing asks 0.8) */
    public const float RECTANGULAR_SHARE = 0.9;
    /** how far the parametric roof may lie from the model's: on average (root mean square) and at most, metres */
    public const float MAX_RMS_METRES = 0.4;
    public const float MAX_DEVIATION_METRES = 1.0;
    /** the model's roof is sampled on a grid of this spacing, metres */
    private const float SAMPLE_SPACING = 1.0;
    private const int MIN_SAMPLES = 8;
    /** pitches the parametric roof is drawn with */
    private const float MIN_PITCH = 5.0;
    private const float MAX_PITCH = 60.0;
    /** roof surfaces at least this large (plan, m²) say where the eaves are; smaller ones are dormer cheeks and edges */
    private const float EAVES_SURFACE = 1.0;
    /** surfaces smaller than this (m², in their plane) are not drawn */
    private const float MIN_DRAWN_AREA = 0.25;
    /** corners closer than this (metres) are one */
    private const float MERGE_DISTANCE = 0.05;
    /** a roof surface this close to vertical (|normal's height part|) has no height to sample */
    private const float MIN_NORMAL_RISE = 0.1;
    private const int DECIMALS = 3;

    public const string RESULT_ROOF = 'roof';
    public const string RESULT_PITCH = 'pitch';
    public const string RESULT_EAVES = 'eaves';
    public const string RESULT_ROOFS = 'roofs';
    public const string RESULT_WALLS = 'walls';

    private function __construct()
    {
    }

    /**
     * The fit and the eaves come from the model built on the cadastre's outline (LoD2); what is
     * drawn can come from a more detailed one of the same building (LoD3: dormers, domes, lanterns).
     *
     * @param list<array{0: float, 1: float}> $outline        the cadastre's footprint, plan metres
     * @param list<Ring>                      $surfaces       the model's roof surfaces
     * @param list<Ring>                      $walls          the model's wall surfaces
     * @param list<Ring>|null                 $detailSurfaces the detailed model's roof surfaces, drawn instead when given
     * @param list<Ring>|null                 $detailWalls    the detailed model's wall surfaces
     *
     * @return array{eaves: float, roof?: string, pitch?: float, roofs?: list<list<Point3>>, walls?: list<list<Point3>>}|null
     *                                                                                                                        the parametric roof (roof, pitch) or the model's surfaces (roofs, walls), both with the eaves height; null without a usable model
     */
    public static function choose(array $outline, array $surfaces, array $walls, ?array $detailSurfaces = null, ?array $detailWalls = null): ?array
    {
        $large = array_filter($surfaces, static fn (array $s): bool => Polygon::area($s['points']) >= self::EAVES_SURFACE);
        if ([] === $large) {
            return null;
        }
        $eaves = min(array_map(static fn (array $s): float => min($s['heights']), $large));
        $ridge = max(array_map(static fn (array $s): float => max($s['heights']), $surfaces));

        $fit = self::parametric($outline, $surfaces, $eaves, $ridge);
        if (null !== $fit) {
            return [self::RESULT_EAVES => round($eaves, self::DECIMALS), ...$fit];
        }

        return [
            self::RESULT_EAVES => round($eaves, self::DECIMALS),
            self::RESULT_ROOFS => self::drawable(array_map(self::corners(...), $detailSurfaces ?? $surfaces)),
            self::RESULT_WALLS => self::drawable(array_map(static fn (array $wall): array => self::above(self::corners($wall), $eaves), null === $detailSurfaces ? $walls : ($detailWalls ?? []))),
        ];
    }

    /**
     * The parametric roof that comes closest to the model, if it is close enough.
     *
     * @param list<array{0: float, 1: float}> $outline
     * @param list<Ring>                      $surfaces
     *
     * @return array{roof: string, pitch: float}|null
     */
    private static function parametric(array $outline, array $surfaces, float $eaves, float $ridge): ?array
    {
        $rectangle = PitchedRoof::rectangleAround($outline, self::RECTANGULAR_SHARE);
        if (null === $rectangle) {
            return null;
        }
        $pitch = PitchedRoof::pitchFor($rectangle, $ridge - $eaves);
        if ($pitch < self::MIN_PITCH || $pitch > self::MAX_PITCH) {
            return null;
        }
        $samples = self::samples($surfaces);
        if (count($samples) < self::MIN_SAMPLES) {
            return null;
        }
        $best = null;
        foreach ([Building::ROOF_GABLE, Building::ROOF_HIP] as $roof) {
            $errors = array_map(static fn (array $s): float => PitchedRoof::heightAt($rectangle, $roof, $eaves, $pitch, $s[0], $s[2]) - $s[1], $samples);
            $rms = sqrt(array_sum(array_map(static fn (float $e): float => $e * $e, $errors)) / count($errors));
            $worst = max(array_map('abs', $errors));
            if ($rms <= self::MAX_RMS_METRES && $worst <= self::MAX_DEVIATION_METRES && (null === $best || $rms < $best[0])) {
                $best = [$rms, $roof];
            }
        }

        return null === $best ? null : [self::RESULT_ROOF => $best[1], self::RESULT_PITCH => round($pitch, 1)];
    }

    /**
     * Points on the model's roof: a grid over each surface, with the surface's height there.
     *
     * @param list<Ring> $surfaces
     *
     * @return list<Point3>
     */
    private static function samples(array $surfaces): array
    {
        $samples = [];
        foreach ($surfaces as $surface) {
            $corners = self::corners($surface);
            $normal = self::normal($corners);
            if (null === $normal || abs($normal[1]) < self::MIN_NORMAL_RISE) {
                continue;
            }
            $xs = array_column($surface['points'], 0);
            $zs = array_column($surface['points'], 1);
            // the grid is anchored to whole metres, so neighbouring surfaces share none of its points twice
            for ($x = ceil(min($xs) / self::SAMPLE_SPACING) * self::SAMPLE_SPACING; $x <= max($xs); $x += self::SAMPLE_SPACING) {
                for ($z = ceil(min($zs) / self::SAMPLE_SPACING) * self::SAMPLE_SPACING; $z <= max($zs); $z += self::SAMPLE_SPACING) {
                    if (Polygon::contains($surface['points'], $x, $z)) {
                        // on the plane through the first corner: n · (p − c) = 0
                        $height = $corners[0][1] - ($normal[0] * ($x - $corners[0][0]) + $normal[2] * ($z - $corners[0][2])) / $normal[1];
                        $samples[] = [$x, $height, $z];
                    }
                }
            }
        }

        return $samples;
    }

    /**
     * @param Ring $ring
     *
     * @return list<Point3>
     */
    private static function corners(array $ring): array
    {
        return array_map(static fn (array $p, float $h): array => [$p[0], $h, $p[1]], $ring['points'], $ring['heights']);
    }

    /**
     * The part of a planar polygon at or above a height (Sutherland–Hodgman against one plane).
     *
     * @param list<Point3> $polygon
     *
     * @return list<Point3>
     */
    private static function above(array $polygon, float $height): array
    {
        $clipped = [];
        for ($i = 0, $n = count($polygon); $i < $n; ++$i) {
            [$a, $b] = [$polygon[$i], $polygon[($i + 1) % $n]];
            $aIn = $a[1] >= $height;
            $bIn = $b[1] >= $height;
            if ($aIn) {
                $clipped[] = $a;
            }
            if ($aIn !== $bIn) {
                $t = ($height - $a[1]) / ($b[1] - $a[1]);
                $clipped[] = [$a[0] + ($b[0] - $a[0]) * $t, $height, $a[2] + ($b[2] - $a[2]) * $t];
            }
        }

        return $clipped;
    }

    /**
     * Polygons cleaned for drawing: corners merged, rounded, too small ones left out.
     *
     * @param list<list<Point3>> $polygons
     *
     * @return list<list<Point3>>
     */
    private static function drawable(array $polygons): array
    {
        $drawable = [];
        foreach ($polygons as $polygon) {
            $clean = [];
            foreach ($polygon as $point) {
                if ([] === $clean || self::distance($point, end($clean)) >= self::MERGE_DISTANCE) {
                    $clean[] = array_map(static fn (float $c): float => round($c, self::DECIMALS), $point);
                }
            }
            while (count($clean) > 1 && self::distance($clean[0], end($clean)) < self::MERGE_DISTANCE) {
                array_pop($clean);
            }
            if (count($clean) >= 3 && self::area($clean) >= self::MIN_DRAWN_AREA) {
                $drawable[] = $clean;
            }
        }

        return $drawable;
    }

    /**
     * Newell's normal of a polygon, of unit length; null for a degenerate one.
     *
     * @param list<Point3> $polygon
     *
     * @return Point3|null
     */
    private static function normal(array $polygon): ?array
    {
        $n = self::newell($polygon);
        $length = sqrt($n[0] ** 2 + $n[1] ** 2 + $n[2] ** 2);

        return $length < 1e-9 ? null : [$n[0] / $length, $n[1] / $length, $n[2] / $length];
    }

    /**
     * The area of a planar polygon in its plane.
     *
     * @param list<Point3> $polygon
     */
    private static function area(array $polygon): float
    {
        $n = self::newell($polygon);

        return sqrt($n[0] ** 2 + $n[1] ** 2 + $n[2] ** 2) / 2;
    }

    /**
     * @param list<Point3> $polygon
     *
     * @return Point3 twice the area, along the normal
     */
    private static function newell(array $polygon): array
    {
        $n = [0.0, 0.0, 0.0];
        for ($i = 0, $count = count($polygon); $i < $count; ++$i) {
            [$a, $b] = [$polygon[$i], $polygon[($i + 1) % $count]];
            $n[0] += ($a[1] - $b[1]) * ($a[2] + $b[2]);
            $n[1] += ($a[2] - $b[2]) * ($a[0] + $b[0]);
            $n[2] += ($a[0] - $b[0]) * ($a[1] + $b[1]);
        }

        return $n;
    }

    /**
     * @param Point3 $a
     * @param Point3 $b
     */
    private static function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }
}

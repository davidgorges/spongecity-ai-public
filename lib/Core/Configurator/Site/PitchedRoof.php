<?php

declare(strict_types=1);

namespace Core\Configurator\Site;

use Core\Configurator\Scenery\Building;

/**
 * The geometry of the parametric pitched roof over a footprint: a gable or hip roof over
 * the smallest rectangle around the outline, the ridge along its long side. Shared by the
 * drawing (SiteBuilder) and by the check whether it is close enough to a building's real
 * roof (RoofFit), so both mean the same roof. Any unit, as long as all are the same.
 */
final class PitchedRoof
{
    /** the pitch drawn when none is known (degrees) */
    public const float DEFAULT_PITCH = 38.0;
    /** a footprint filling at least this share of its rectangle gets a roof over that rectangle */
    public const float RECTANGULAR_SHARE = 0.8;

    private function __construct()
    {
    }

    /**
     * The smallest rectangle around a footprint (one side along one of its edges), if the
     * footprint fills at least $share of it; null for L-shapes, courtyards and other outlines
     * a single roof would not fit.
     *
     * @param list<array{float, float}> $points
     *
     * @return array{float, float, float, float, float, float}|null centre x/z, long direction x/z, half length, half width
     */
    public static function rectangleAround(array $points, float $share = self::RECTANGULAR_SHARE): ?array
    {
        $count = count($points);
        $area = 0.0;
        for ($i = 0; $i < $count; ++$i) {
            [$x1, $z1] = $points[$i];
            [$x2, $z2] = $points[($i + 1) % $count];
            $area += $x1 * $z2 - $x2 * $z1;
        }
        $area = abs($area) / 2;
        $best = null;
        $minimumEdge = self::scale($points) / 1000;
        for ($i = 0; $i < $count; ++$i) {
            [$x1, $z1] = $points[$i];
            [$x2, $z2] = $points[($i + 1) % $count];
            $length = hypot($x2 - $x1, $z2 - $z1);
            if ($length < $minimumEdge) {
                continue;
            }
            [$ux, $uz] = [($x2 - $x1) / $length, ($z2 - $z1) / $length];
            $us = array_map(static fn (array $p): float => $p[0] * $ux + $p[1] * $uz, $points);
            $vs = array_map(static fn (array $p): float => -$p[0] * $uz + $p[1] * $ux, $points);
            [$u0, $u1, $v0, $v1] = [min($us), max($us), min($vs), max($vs)];
            $box = ($u1 - $u0) * ($v1 - $v0);
            if (null === $best || $box < $best[0]) {
                $best = [$box, $ux, $uz, $u0, $u1, $v0, $v1];
            }
        }
        if (null === $best || $best[0] <= 0 || $area / $best[0] < $share) {
            return null;
        }
        [, $ux, $uz, $u0, $u1, $v0, $v1] = $best;
        [$u, $v] = [($u0 + $u1) / 2, ($v0 + $v1) / 2];
        [$cx, $cz] = [$u * $ux - $v * $uz, $u * $uz + $v * $ux];
        [$halfU, $halfV] = [($u1 - $u0) / 2, ($v1 - $v0) / 2];

        // the ridge runs along the long side
        return $halfU >= $halfV ? [$cx, $cz, $ux, $uz, $halfU, $halfV] : [$cx, $cz, -$uz, $ux, $halfV, $halfU];
    }

    /**
     * How high the roof is at a point of the plan within its rectangle: the eaves at the long
     * sides, rising at the pitch to the ridge; a hip roof rises from the short sides as well.
     * A monopitch roof is drawn as a gable (SiteBuilder), so it is measured as one.
     *
     * @param array{float, float, float, float, float, float} $rectangle as rectangleAround()
     */
    public static function heightAt(array $rectangle, string $roof, float $eaves, float $pitchDegrees, float $x, float $z): float
    {
        [$cx, $cz, $dx, $dz, $a, $b] = $rectangle;
        $u = abs(($x - $cx) * $dx + ($z - $cz) * $dz);
        $v = abs(-($x - $cx) * $dz + ($z - $cz) * $dx);
        $run = max(0.0, $b - $v);
        if (Building::ROOF_HIP === $roof) {
            $run = min($run, max(0.0, $a - $u));
        }

        return $eaves + $run * tan(deg2rad($pitchDegrees));
    }

    /**
     * The pitch that puts the ridge at a height above the eaves.
     *
     * @param array{float, float, float, float, float, float} $rectangle
     */
    public static function pitchFor(array $rectangle, float $rise): float
    {
        return $rectangle[5] > 0 ? rad2deg(atan($rise / $rectangle[5])) : 0.0;
    }

    /**
     * @param list<array{float, float}> $points
     */
    private static function scale(array $points): float
    {
        $xs = array_column($points, 0);
        $zs = array_column($points, 1);

        return [] === $xs ? 0.0 : max(max($xs) - min($xs), max($zs) - min($zs));
    }
}

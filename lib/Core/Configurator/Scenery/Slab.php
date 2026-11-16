<?php

declare(strict_types=1);

namespace Core\Configurator\Scenery;

use Core\Configurator\Assembly\Extrusion;
use Core\Configurator\Assembly\Part;
use Core\Configurator\Assembly\Turn;
use Core\Configurator\Assembly\Vector3;

/**
 * Flat pieces of scenery from polygons, built from an Extrusion: a slab in
 * any plane (roof faces, gable walls) or lying on the ground (paving, prisms
 * standing on an outline). Concave outlines are fine: the browser
 * triangulates the profile. Millimetres.
 */
final class Slab
{
    /** turns an extrusion (profile in local x/y, extruded along z) to lie flat: profile (x, y) → plan (x, z) */
    private const float FLAT = M_PI / 2;
    private const float EPSILON = 1e-9;

    private function __construct()
    {
    }

    /**
     * A slab of $thickness in the plane of the points (in order around the
     * polygon, at least three, not on one line), lying on the side its
     * normal points to (upwards for anything not vertical), or centred on
     * the plane (walls).
     *
     * @param list<array{float, float, float}> $points
     */
    public static function planar(string $label, string $material, array $points, float $thickness, bool $centred = false): ?Part
    {
        $origin = $points[0];
        $u = self::normalize(self::sub($points[1], $origin));
        $n = null;
        // the first point that is not on the line through the first two spans the plane
        for ($i = 2, $count = count($points); $i < $count && null === $n; ++$i) {
            $n = self::normalize(self::cross($u ?? [0.0, 0.0, 0.0], self::sub($points[$i], $origin)));
        }
        if (null === $u || null === $n) {
            return null;
        }
        if ($n[1] < -self::EPSILON) {
            $n = [-$n[0], -$n[1], -$n[2]];
        }
        $v = self::cross($n, $u);
        $lift = $centred ? 0.0 : $thickness / 2;
        $profile = array_map(static fn (array $p): array => [self::dot(self::sub($p, $origin), $u), self::dot(self::sub($p, $origin), $v)], $points);

        return new Part(
            null,
            $label,
            new Extrusion($profile, $thickness),
            $material,
            new Vector3($origin[0] + $n[0] * $lift, $origin[1] + $n[1] * $lift, $origin[2] + $n[2] * $lift),
            Turn::fromBasis($u, $v, $n),
        );
    }

    /**
     * An outline in plan (x, z) as a body from $bottom up to $bottom + $height.
     *
     * @param list<array{float, float}> $outline
     */
    public static function onGround(string $label, string $material, array $outline, float $height, float $bottom = 0.0): Part
    {
        // lying flat, the profile's y runs along z and the extrusion downwards from its centre
        return new Part(
            null,
            $label,
            new Extrusion($outline, $height),
            $material,
            new Vector3(0, $bottom + $height / 2, 0),
            new Vector3(self::FLAT, 0, 0),
        );
    }

    /**
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     *
     * @return array{float, float, float}
     */
    private static function sub(array $a, array $b): array
    {
        return [$a[0] - $b[0], $a[1] - $b[1], $a[2] - $b[2]];
    }

    /**
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     *
     * @return array{float, float, float}
     */
    private static function cross(array $a, array $b): array
    {
        return [$a[1] * $b[2] - $a[2] * $b[1], $a[2] * $b[0] - $a[0] * $b[2], $a[0] * $b[1] - $a[1] * $b[0]];
    }

    /**
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     */
    private static function dot(array $a, array $b): float
    {
        return $a[0] * $b[0] + $a[1] * $b[1] + $a[2] * $b[2];
    }

    /**
     * @param array{float, float, float} $a
     *
     * @return array{float, float, float}|null null for a zero vector
     */
    private static function normalize(array $a): ?array
    {
        $length = sqrt(self::dot($a, $a));

        return $length < 1e-6 ? null : [$a[0] / $length, $a[1] / $length, $a[2] / $length];
    }
}

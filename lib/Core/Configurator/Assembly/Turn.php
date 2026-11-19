<?php

declare(strict_types=1);

namespace Core\Configurator\Assembly;

/**
 * Turns a part's rotation (Euler angles, order XYZ as in three.js) about the
 * world's vertical axis, e.g. to place a whole piece of scenery turned. A
 * part tilted about X (a roof plane) cannot simply add the angle to its Y
 * rotation: the rotations are composed as matrices and read back as Euler
 * angles.
 */
final class Turn
{
    private const float GIMBAL_LIMIT = 0.9999999;

    private function __construct()
    {
    }

    public static function aboutY(Vector3 $rotation, float $angle): Vector3
    {
        if (0.0 === $angle) {
            return $rotation;
        }
        if (0.0 === $rotation->x && 0.0 === $rotation->z) {
            return new Vector3(0.0, $rotation->y + $angle, 0.0);
        }

        return self::anglesOf(self::multiply(self::y($angle), self::euler($rotation)));
    }

    /**
     * Euler angles (XYZ, as three.js) of the rotation that turns the local
     * axes onto these unit vectors: local x onto $u, y onto $v, z onto $n.
     *
     * @param array{float, float, float} $u
     * @param array{float, float, float} $v
     * @param array{float, float, float} $n
     */
    public static function fromBasis(array $u, array $v, array $n): Vector3
    {
        return self::anglesOf([[$u[0], $v[0], $n[0]], [$u[1], $v[1], $n[1]], [$u[2], $v[2], $n[2]]]);
    }

    /**
     * @param array<int, array<int, float>> $m rotation matrix
     */
    private static function anglesOf(array $m): Vector3
    {
        $y = asin(max(-1.0, min(1.0, $m[0][2])));
        if (abs($m[0][2]) < self::GIMBAL_LIMIT) {
            return new Vector3(atan2(-$m[1][2], $m[2][2]), $y, atan2(-$m[0][1], $m[0][0]));
        }

        return new Vector3(atan2($m[2][1], $m[1][1]), $y, 0.0);
    }

    /** @return array<int, array<int, float>> */
    private static function euler(Vector3 $r): array
    {
        return self::multiply(self::multiply(self::x($r->x), self::y($r->y)), self::z($r->z));
    }

    /** @return array<int, array<int, float>> */
    private static function x(float $a): array
    {
        return [[1.0, 0.0, 0.0], [0.0, cos($a), -sin($a)], [0.0, sin($a), cos($a)]];
    }

    /** @return array<int, array<int, float>> */
    private static function y(float $a): array
    {
        return [[cos($a), 0.0, sin($a)], [0.0, 1.0, 0.0], [-sin($a), 0.0, cos($a)]];
    }

    /** @return array<int, array<int, float>> */
    private static function z(float $a): array
    {
        return [[cos($a), -sin($a), 0.0], [sin($a), cos($a), 0.0], [0.0, 0.0, 1.0]];
    }

    /**
     * @param array<int, array<int, float>> $a
     * @param array<int, array<int, float>> $b
     *
     * @return array<int, array<int, float>>
     */
    private static function multiply(array $a, array $b): array
    {
        $result = [];
        for ($r = 0; $r < 3; ++$r) {
            for ($c = 0; $c < 3; ++$c) {
                $result[$r][$c] = $a[$r][0] * $b[0][$c] + $a[$r][1] * $b[1][$c] + $a[$r][2] * $b[2][$c];
            }
        }

        return $result;
    }
}

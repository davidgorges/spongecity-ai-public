<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

/**
 * Plan geometry for impact calculations. Points are [x, z] in millimetres,
 * areas come out in square millimetres.
 */
final class Polygon
{
    private function __construct()
    {
    }

    /**
     * @param list<array{0: float|int, 1: float|int}> $points
     */
    public static function area(array $points): float
    {
        $sum = 0.0;
        $count = count($points);
        foreach ($points as $i => [$ax, $az]) {
            [$bx, $bz] = $points[($i + 1) % $count];
            $sum += $ax * $bz - $bx * $az;
        }

        return abs($sum) / 2;
    }

    /**
     * The length of a closed outline, millimetres.
     *
     * @param list<array{0: float|int, 1: float|int}> $points
     */
    public static function perimeter(array $points): float
    {
        $length = 0.0;
        $count = count($points);
        foreach ($points as $i => [$ax, $az]) {
            [$bx, $bz] = $points[($i + 1) % $count];
            $length += hypot($bx - $ax, $bz - $az);
        }

        return $length;
    }

    /**
     * The outline moved inwards by $distance (millimetres), corner by corner along the bisector,
     * a sharp corner moving at most $maxMiter times the distance. Not clipped: a part narrower
     * than twice the distance folds over itself, so check the width first.
     *
     * @param list<array{0: float|int, 1: float|int}> $points
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function inset(array $points, float $distance, float $maxMiter = 3.0): array
    {
        $count = count($points);
        if ($distance <= 0.0 || $count < 3) {
            return array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $points);
        }
        $signed = 0.0;
        foreach ($points as $i => [$x, $z]) {
            [$nx, $nz] = $points[($i + 1) % $count];
            $signed += $x * $nz - $nx * $z;
        }
        // which side is inside: left of each edge when the outline runs counter-clockwise (in x/z)
        $side = $signed > 0 ? 1.0 : -1.0;
        $normal = static function (array $a, array $b) use ($side): array {
            $length = hypot($b[0] - $a[0], $b[1] - $a[1]);

            return $length > 0 ? [-$side * ($b[1] - $a[1]) / $length, $side * ($b[0] - $a[0]) / $length] : [0.0, 0.0];
        };
        $inset = [];
        foreach ($points as $i => $point) {
            $before = $normal($points[($i - 1 + $count) % $count], $point);
            $after = $normal($point, $points[($i + 1) % $count]);
            [$mx, $mz] = [$before[0] + $after[0], $before[1] + $after[1]];
            $dot = $before[0] * $after[0] + $before[1] * $after[1];
            $scale = min($maxMiter, 1.0 / max(1e-6, sqrt((1.0 + $dot) / 2.0)));
            $length = hypot($mx, $mz);
            $inset[] = $length > 1e-9
                ? [$point[0] + $mx / $length * $distance * $scale, $point[1] + $mz / $length * $distance * $scale]
                : [$point[0] + $after[0] * $distance, $point[1] + $after[1] * $distance];
        }

        return $inset;
    }

    /**
     * The area two outlines share, either of them concave: $a clipped to each triangle of $b
     * (Sutherland–Hodgman is exact against a convex clip, whatever the subject's shape).
     *
     * @param list<array{0: float|int, 1: float|int}> $a
     * @param list<array{0: float|int, 1: float|int}> $b
     */
    public static function overlapArea(array $a, array $b): float
    {
        if (count($a) < 3 || count($b) < 3 || !self::boundsOverlap(self::bounds($a), self::bounds($b))) {
            return 0.0;
        }
        $area = 0.0;
        foreach (self::triangles($b) as $triangle) {
            $clipped = self::clip($a, $triangle);
            if (count($clipped) >= 3) {
                $area += self::area($clipped);
            }
        }

        return $area;
    }

    /**
     * A simple polygon cut into triangles by ear clipping, each counter-clockwise (x east, z south
     * taken as a plain x/y plane).
     *
     * @param list<array{0: float|int, 1: float|int}> $points
     *
     * @return list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}, 2: array{0: float, 1: float}}>
     */
    public static function triangles(array $points): array
    {
        $ring = [];
        foreach ($points as [$x, $z]) {
            $point = [(float) $x, (float) $z];
            if ([] === $ring || $ring[count($ring) - 1] !== $point) {
                $ring[] = $point;
            }
        }
        if (count($ring) > 1 && $ring[0] === $ring[count($ring) - 1]) {
            array_pop($ring); // a closed ring repeats its first corner
        }
        if (self::signedArea($ring) < 0) {
            $ring = array_reverse($ring);
        }

        $triangles = [];
        $guard = count($ring) * count($ring);
        while (count($ring) > 3 && $guard-- > 0) {
            $count = count($ring);
            for ($i = 0; $i < $count; ++$i) {
                $prev = $ring[($i - 1 + $count) % $count];
                $ear = $ring[$i];
                $next = $ring[($i + 1) % $count];
                $turn = self::cross($prev, $ear, $next);
                if ($turn < 0) {
                    continue; // a reflex corner is no ear
                }
                if ($turn > 0 && self::anyInside($ring, [$prev, $ear, $next])) {
                    continue;
                }
                if ($turn > 0) {
                    $triangles[] = [$prev, $ear, $next];
                }
                array_splice($ring, $i, 1); // a straight corner (turn 0) is dropped without a triangle
                continue 2;
            }
            break; // no ear left: not a simple polygon, keep what was cut
        }
        if (3 === count($ring) && self::cross($ring[0], $ring[1], $ring[2]) > 0) {
            $triangles[] = [$ring[0], $ring[1], $ring[2]];
        }

        return $triangles;
    }

    /**
     * @param list<array{0: float|int, 1: float|int}> $points
     */
    private static function signedArea(array $points): float
    {
        $sum = 0.0;
        $count = count($points);
        foreach ($points as $i => [$ax, $az]) {
            [$bx, $bz] = $points[($i + 1) % $count];
            $sum += $ax * $bz - $bx * $az;
        }

        return $sum / 2;
    }

    /**
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     * @param array{0: float, 1: float} $c
     */
    private static function cross(array $a, array $b, array $c): float
    {
        return ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
    }

    /**
     * @param list<array{0: float, 1: float}>                                                                 $ring
     * @param array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}, 2: array{0: float, 1: float}} $triangle
     */
    private static function anyInside(array $ring, array $triangle): bool
    {
        foreach ($ring as $point) {
            if (in_array($point, $triangle, true)) {
                continue;
            }
            if (self::cross($triangle[0], $triangle[1], $point) >= 0
                && self::cross($triangle[1], $triangle[2], $point) >= 0
                && self::cross($triangle[2], $triangle[0], $point) >= 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sutherland–Hodgman: the subject cut to a counter-clockwise convex polygon.
     *
     * @param list<array{0: float|int, 1: float|int}> $subject
     * @param list<array{0: float, 1: float}>         $convex
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function clip(array $subject, array $convex): array
    {
        $output = array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $subject);
        $edges = count($convex);
        for ($e = 0; $e < $edges && [] !== $output; ++$e) {
            $from = $convex[$e];
            $to = $convex[($e + 1) % $edges];
            $input = $output;
            $output = [];
            $count = count($input);
            foreach ($input as $i => $current) {
                $previous = $input[($i - 1 + $count) % $count];
                $currentIn = self::cross($from, $to, $current) >= 0;
                $previousIn = self::cross($from, $to, $previous) >= 0;
                if ($currentIn !== $previousIn) {
                    $output[] = self::intersection($previous, $current, $from, $to);
                }
                if ($currentIn) {
                    $output[] = $current;
                }
            }
        }

        return $output;
    }

    /**
     * Where the segment p→q crosses the line through a and b.
     *
     * @param array{0: float, 1: float} $p
     * @param array{0: float, 1: float} $q
     * @param array{0: float, 1: float} $a
     * @param array{0: float, 1: float} $b
     *
     * @return array{0: float, 1: float}
     */
    private static function intersection(array $p, array $q, array $a, array $b): array
    {
        $dp = self::cross($a, $b, $p);
        $dq = self::cross($a, $b, $q);
        $t = $dp / ($dp - $dq);

        return [$p[0] + ($q[0] - $p[0]) * $t, $p[1] + ($q[1] - $p[1]) * $t];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $a
     * @param array{0: float, 1: float, 2: float, 3: float} $b
     */
    private static function boundsOverlap(array $a, array $b): bool
    {
        return $a[0] < $b[1] && $b[0] < $a[1] && $a[2] < $b[3] && $b[2] < $a[3];
    }

    /**
     * @param list<array{0: float|int, 1: float|int}> $points
     *
     * @return array{0: float, 1: float, 2: float, 3: float} minX, maxX, minZ, maxZ
     */
    public static function bounds(array $points): array
    {
        $xs = array_column($points, 0);
        $zs = array_column($points, 1);

        return [(float) min($xs), (float) max($xs), (float) min($zs), (float) max($zs)];
    }

    /**
     * @param list<array{0: float|int, 1: float|int}> $polygon
     */
    public static function contains(array $polygon, float $x, float $z): bool
    {
        $inside = false;
        $count = count($polygon);
        foreach ($polygon as $i => [$ax, $az]) {
            [$bx, $bz] = $polygon[($i + 1) % $count];
            if (($az > $z) !== ($bz > $z) && $x < $ax + ($bx - $ax) * ($z - $az) / ($bz - $az)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }
}

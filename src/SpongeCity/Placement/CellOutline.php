<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\SiteObject as O;

/**
 * Turns a set of open-ground cells into the outline of an intervention: the cells' border traced,
 * its staircase simplified into straight lines (paving, a zone) or rounded into a soft edge (a
 * planted basin), with at most MAX_POINTS corners: drawn as a plan is, not as a pixel shape.
 */
final readonly class CellOutline
{
    /** the staircase of the cells is straightened within this, metres */
    private const float SIMPLIFY_METRES = 0.6;
    /** Chaikin's corner cutting: twice gives a soft, still recognisable edge */
    private const int SMOOTHING = 2;
    /** the smoothed edge is thinned out within this first, then more until it fits MAX_POINTS */
    private const float THIN_METRES = 0.12;
    private const float THIN_GROWTH = 1.5;
    public const int MAX_POINTS = O::MAX_POINTS;

    /**
     * @param array<int, true> $cells   a 4-connected set without holes or diagonal-only contacts
     * @param bool             $rounded a soft edge (a planted basin); else straight lines (paving, a zone)
     *
     * @return list<array{0: float, 1: float}> the outline in plan metres, or [] when there is none
     */
    public static function of(OpenGround $ground, array $cells, bool $rounded = true): array
    {
        $ring = self::trace($ground, self::cutHoles($ground, $cells));
        if (count($ring) < 3) {
            return [];
        }
        $outline = self::simplify($ring, self::SIMPLIFY_METRES);
        for ($i = 0; $rounded && $i < self::SMOOTHING; ++$i) {
            $outline = self::chaikin($outline);
        }
        for ($tolerance = self::THIN_METRES; count($outline) > self::MAX_POINTS; $tolerance *= self::THIN_GROWTH) {
            $outline = self::simplify($outline, $tolerance);
        }

        return count($outline) >= 3 ? $outline : [];
    }

    /**
     * The cells' outer border, corner by corner (plan metres): every cell side without a neighbour
     * in the set, chained into loops; the largest loop.
     *
     * @param array<int, true> $cells
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function trace(OpenGround $ground, array $cells): array
    {
        $columns = $ground->columns;
        $in = static fn (int $c, int $r): bool => $c >= 0 && $r >= 0 && $c < $columns && $r < $ground->rows && isset($cells[$r * $columns + $c]);
        // corners on a grid one wider than the cells; each side walked with the cell on the same hand
        $key = static fn (int $c, int $r): int => $r * ($columns + 1) + $c;
        $next = [];
        foreach (array_keys($cells) as $cell) {
            [$c, $r] = [$cell % $columns, intdiv($cell, $columns)];
            if (!$in($c, $r - 1)) {
                $next[$key($c, $r)] = $key($c + 1, $r);
            }
            if (!$in($c + 1, $r)) {
                $next[$key($c + 1, $r)] = $key($c + 1, $r + 1);
            }
            if (!$in($c, $r + 1)) {
                $next[$key($c + 1, $r + 1)] = $key($c, $r + 1);
            }
            if (!$in($c - 1, $r)) {
                $next[$key($c, $r + 1)] = $key($c, $r);
            }
        }

        $best = [];
        $bestArea = 0.0;
        while ([] !== $next) {
            $start = array_key_first($next);
            $loop = [];
            for ($corner = $start, $guard = count($next) + 1; null !== $corner && $guard-- > 0;) {
                $loop[] = [$ground->originX + ($corner % ($columns + 1)) * OpenGround::CELL_METRES, $ground->originZ + intdiv($corner, $columns + 1) * OpenGround::CELL_METRES];
                $following = $next[$corner] ?? null;
                unset($next[$corner]);
                $corner = $following === $start ? null : $following;
            }
            $area = count($loop) >= 3 ? Polygon::area($loop) : 0.0;
            if ($area > $bestArea) {
                [$best, $bestArea] = [$loop, $area];
            }
        }

        return $best;
    }

    /**
     * A set that encloses something (a yard around a building) has no single outline: each enclosed
     * gap gets a cut, one cell wide, straight up (north) to the outside.
     *
     * @param array<int, true> $cells
     *
     * @return array<int, true>
     */
    public static function cutHoles(OpenGround $ground, array $cells): array
    {
        if ([] === $cells) {
            return $cells;
        }
        $columns = $ground->columns;
        $cs = array_map(static fn (int $cell): int => $cell % $columns, array_keys($cells));
        $rs = array_map(static fn (int $cell): int => intdiv($cell, $columns), array_keys($cells));
        [$minC, $maxC, $minR, $maxR] = [min($cs) - 1, max($cs) + 1, min($rs) - 1, max($rs) + 1];
        // what the frame's border reaches without crossing the set is outside; the rest that is not the set is a hole
        $outside = [];
        $stack = [];
        $key = static fn (int $c, int $r): string => $c.':'.$r;
        for ($c = $minC; $c <= $maxC; ++$c) {
            foreach ([$minR, $maxR] as $r) {
                $stack[] = [$c, $r];
            }
        }
        for ($r = $minR; $r <= $maxR; ++$r) {
            foreach ([$minC, $maxC] as $c) {
                $stack[] = [$c, $r];
            }
        }
        $inSet = static fn (int $c, int $r): bool => $c >= 0 && $r >= 0 && $c < $columns && isset($cells[$r * $columns + $c]);
        while ([] !== $stack) {
            [$c, $r] = array_pop($stack);
            if ($c < $minC || $c > $maxC || $r < $minR || $r > $maxR || isset($outside[$key($c, $r)]) || $inSet($c, $r)) {
                continue;
            }
            $outside[$key($c, $r)] = true;
            array_push($stack, [$c + 1, $r], [$c - 1, $r], [$c, $r + 1], [$c, $r - 1]);
        }
        for ($r = $minR + 1; $r < $maxR; ++$r) {
            for ($c = $minC + 1; $c < $maxC; ++$c) {
                if ($inSet($c, $r) || isset($outside[$key($c, $r)])) {
                    continue;
                }
                // the top of a hole: cut the set above it until the outside
                for ($up = $r - 1; $up > $minR && $inSet($c, $up); --$up) {
                    unset($cells[$up * $columns + $c]);
                }
                // the cut joins the hole to the outside: the rest of this hole is outside now
                $stack = [[$c, $r]];
                while ([] !== $stack) {
                    [$hc, $hr] = array_pop($stack);
                    if ($hc <= $minC || $hc >= $maxC || $hr <= $minR || $hr >= $maxR || isset($outside[$key($hc, $hr)]) || $inSet($hc, $hr)) {
                        continue;
                    }
                    $outside[$key($hc, $hr)] = true;
                    array_push($stack, [$hc + 1, $hr], [$hc - 1, $hr], [$hc, $hr + 1], [$hc, $hr - 1]);
                }
            }
        }

        return $cells;
    }

    /**
     * Douglas–Peucker on a closed ring: split at the corner farthest from the first, each half
     * straightened where it strays less than the tolerance.
     *
     * @param list<array{0: float, 1: float}> $ring
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function simplify(array $ring, float $tolerance): array
    {
        $count = count($ring);
        if ($count <= 4) {
            return $ring;
        }
        $far = 0;
        $farthest = -1.0;
        foreach ($ring as $i => [$x, $z]) {
            $d = hypot($x - $ring[0][0], $z - $ring[0][1]);
            if ($d > $farthest) {
                [$far, $farthest] = [$i, $d];
            }
        }
        $first = self::line([...array_slice($ring, 0, $far + 1)], $tolerance);
        $second = self::line([...array_slice($ring, $far), $ring[0]], $tolerance);

        return [...array_slice($first, 0, -1), ...array_slice($second, 0, -1)];
    }

    /**
     * @param list<array{0: float, 1: float}> $points an open line
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function line(array $points, float $tolerance): array
    {
        $count = count($points);
        if ($count < 3) {
            return $points;
        }
        [$ax, $az] = $points[0];
        [$bx, $bz] = $points[$count - 1];
        $length = hypot($bx - $ax, $bz - $az);
        $index = 0;
        $max = 0.0;
        for ($i = 1; $i < $count - 1; ++$i) {
            [$x, $z] = $points[$i];
            $d = $length > 0 ? abs(($bx - $ax) * ($az - $z) - ($ax - $x) * ($bz - $az)) / $length : hypot($x - $ax, $z - $az);
            if ($d > $max) {
                [$index, $max] = [$i, $d];
            }
        }
        if ($max <= $tolerance) {
            return [$points[0], $points[$count - 1]];
        }
        $left = self::line(array_slice($points, 0, $index + 1), $tolerance);
        $right = self::line(array_slice($points, $index), $tolerance);

        return [...array_slice($left, 0, -1), ...$right];
    }

    /**
     * Chaikin: every corner cut at a quarter of its edges.
     *
     * @param list<array{0: float, 1: float}> $ring
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function chaikin(array $ring): array
    {
        $smooth = [];
        $count = count($ring);
        foreach ($ring as $i => [$ax, $az]) {
            [$bx, $bz] = $ring[($i + 1) % $count];
            $smooth[] = [0.75 * $ax + 0.25 * $bx, 0.75 * $az + 0.25 * $bz];
            $smooth[] = [0.25 * $ax + 0.75 * $bx, 0.25 * $az + 0.75 * $bz];
        }

        return $smooth;
    }
}

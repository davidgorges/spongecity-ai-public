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

<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

/**
 * What stands over the ground, rasterised once: on a grid of SHADE_CELL millimetres the height of
 * the tallest building and the tree crowns (from their base to their top), over the plot and the
 * strip towards the sun a shadow can come from. A ray towards the sun then asks a few cells instead
 * of testing every footprint at every step (tens of millions of point-in-polygon tests on a schoolyard).
 */
final class ShadeMap
{
    /** millimetres: a shadow's edge to a quarter of a metre (as close to the footprints' own outlines as the shade share shows) */
    public const float CELL = 250.0;

    /** @var array<int, float> cell → height of the tallest building over it, millimetres */
    private array $buildings = [];
    /** @var array<int, list<array{0: float, 1: float}>> cell → the crowns over it, [base, top] in millimetres */
    private array $crowns = [];
    private float $tallest = 0.0;

    private function __construct(
        private readonly float $originX,
        private readonly float $originZ,
        private readonly int $columns,
        private readonly int $rows,
    ) {
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float}                           $bounds     the plot's minX, maxX, minZ, maxZ (mm)
     * @param list<array{polygon: list<array{0: float, 1: float}>, height: float}>    $buildings
     * @param list<array{x: float, z: float, radius: float, base: float, top: float}> $trees
     * @param array{0: float, 1: float}                                               $towardsSun unit vector (x, z)
     */
    public static function of(array $bounds, array $buildings, array $trees, array $towardsSun, float $reach): self
    {
        [$minX, $maxX, $minZ, $maxZ] = $bounds;
        // the plot, and as far towards the sun as a ray reaches
        $minX += min(0.0, $towardsSun[0] * $reach);
        $maxX += max(0.0, $towardsSun[0] * $reach);
        $minZ += min(0.0, $towardsSun[1] * $reach);
        $maxZ += max(0.0, $towardsSun[1] * $reach);
        $map = new self($minX, $minZ, (int) ceil(($maxX - $minX) / self::CELL), (int) ceil(($maxZ - $minZ) / self::CELL));

        foreach ($buildings as $building) {
            if (count($building['polygon']) < 3 || $building['height'] <= 0.0) {
                continue;
            }
            $map->tallest = max($map->tallest, $building['height']);
            $map->fill($building['polygon'], static function (int $cell) use ($map, $building): void {
                $map->buildings[$cell] = max($map->buildings[$cell] ?? 0.0, $building['height']);
            });
        }
        foreach ($trees as $tree) {
            if ($tree['radius'] <= 0.0) {
                continue;
            }
            $map->tallest = max($map->tallest, $tree['top']);
            $map->disc($tree['x'], $tree['z'], $tree['radius'], static function (int $cell) use ($map, $tree): void {
                $map->crowns[$cell][] = [$tree['base'], $tree['top']];
            });
        }

        return $map;
    }

    /** millimetres: no ray above this meets anything */
    public function tallest(): float
    {
        return $this->tallest;
    }

    /** Whether something stands over plan x/z (mm) at least as high as $height (mm). */
    public function blocks(float $x, float $z, float $height): bool
    {
        return null !== $this->blocker($x, $z, $height);
    }

    /** What stands over plan x/z (mm) at least as high as $height (mm): a building, a tree crown or nothing (null). */
    public function blocker(float $x, float $z, float $height): ?ShadeKind
    {
        $c = (int) floor(($x - $this->originX) / self::CELL);
        $r = (int) floor(($z - $this->originZ) / self::CELL);
        if ($c < 0 || $r < 0 || $c >= $this->columns || $r >= $this->rows) {
            return null;
        }
        $cell = $r * $this->columns + $c;
        if ($height <= ($this->buildings[$cell] ?? -1.0)) {
            return ShadeKind::Building;
        }
        foreach ($this->crowns[$cell] ?? [] as [$base, $top]) {
            if ($height >= $base && $height <= $top) {
                return ShadeKind::Tree;
            }
        }

        return null;
    }

    /**
     * Calls $each for every cell whose centre lies inside the polygon (a scanline per row: the same
     * even-odd rule as Polygon::contains, at the cell centres).
     *
     * @param list<array{0: float, 1: float}> $polygon
     * @param callable(int): void             $each
     */
    private function fill(array $polygon, callable $each): void
    {
        [, , $minZ, $maxZ] = Polygon::bounds($polygon);
        $count = count($polygon);
        $first = max(0, (int) floor(($minZ - $this->originZ) / self::CELL));
        $last = min($this->rows - 1, (int) floor(($maxZ - $this->originZ) / self::CELL));
        for ($r = $first; $r <= $last; ++$r) {
            $z = $this->originZ + ($r + 0.5) * self::CELL;
            $crossings = [];
            for ($i = 0; $i < $count; ++$i) {
                [$ax, $az] = $polygon[$i];
                [$bx, $bz] = $polygon[($i + 1) % $count];
                if (($az > $z) !== ($bz > $z)) {
                    $crossings[] = $ax + ($bx - $ax) * ($z - $az) / ($bz - $az);
                }
            }
            sort($crossings);
            for ($k = 0; $k + 1 < count($crossings); $k += 2) {
                $this->span($r, $crossings[$k], $crossings[$k + 1], $each);
            }
        }
    }

    /**
     * @param callable(int): void $each
     */
    private function disc(float $x, float $z, float $radius, callable $each): void
    {
        $first = max(0, (int) floor(($z - $radius - $this->originZ) / self::CELL));
        $last = min($this->rows - 1, (int) floor(($z + $radius - $this->originZ) / self::CELL));
        for ($r = $first; $r <= $last; ++$r) {
            $dz = $this->originZ + ($r + 0.5) * self::CELL - $z;
            if (abs($dz) > $radius) {
                continue;
            }
            $half = sqrt($radius * $radius - $dz * $dz);
            // a crown's edge counts as under it, as hypot() <= radius did
            $this->span($r, $x - $half, $x + $half + 1e-6, $each);
        }
    }

    /**
     * The cells of row $r whose centres lie in [$from, $to).
     *
     * @param callable(int): void $each
     */
    private function span(int $r, float $from, float $to, callable $each): void
    {
        $first = max(0, (int) ceil(($from - $this->originX) / self::CELL - 0.5));
        $last = min($this->columns - 1, (int) ceil(($to - $this->originX) / self::CELL - 0.5) - 1);
        for ($c = $first; $c <= $last; ++$c) {
            $each($r * $this->columns + $c);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;

/**
 * The plot on a one-metre grid (plan metres, x east, z south): for every cell what rules it out
 * for a ground intervention, and how far it is from the nearest building wall. What counts as
 * far enough from a building depends on the intervention (an infiltrating rain garden needs more
 * than paving), so the distance is kept and each InterventionSiting applies its own clearance.
 */
final readonly class OpenGround
{
    public const float CELL_METRES = 1.0;

    public const string OUTSIDE = 'outside the plot';
    public const string PLOT_EDGE = 'at the plot edge';
    public const string BUILDING = 'building';
    public const string ROOT_ZONE = 'root zone of a tree';
    public const string PATH = 'street or path';
    public const string PLANNED = 'planned intervention';
    public const string EQUIPMENT = 'play equipment (its fall zone, DIN EN 1176)';
    /** play and sports areas keep their surfacing: no rain garden there (paving keeps off them by their use) */
    public const string PLAY_AREA = 'play or sports area';
    /** the uses of an area whose ground is a PLAY_AREA */
    public const array PLAY_USES = [O::AREA_PLAY, O::AREA_SPORT];

    /** keep this far inside the plot outline */
    private const float PLOT_MARGIN_METRES = 1.0;
    /** DIN 18920: a tree's root zone is its crown's drip line plus 1.5 m */
    public const float ROOT_ZONE_BEYOND_CROWN_METRES = 1.5;
    /** beside a path: the paved width plus this */
    private const float PATH_MARGIN_METRES = 0.5;
    /** keep this far from an intervention already planned */
    private const float PLANNED_MARGIN_METRES = 1.0;
    /** wall distances beyond this are not needed by any rule */
    public const float FAR_FROM_BUILDINGS_METRES = 20.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;

    /**
     * @param list<string|null> $blocked          per cell: what rules it out besides the clearance from buildings
     * @param list<float>       $buildingDistance per cell: metres to the nearest building wall, 0 inside one
     */
    private function __construct(
        public float $originX,
        public float $originZ,
        public int $columns,
        public int $rows,
        public array $blocked,
        public array $buildingDistance,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $objects the plan's normalised site objects
     */
    public static function of(array $objects): self
    {
        $metres = static fn (array $outline): array => array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], $outline);
        $ofType = static fn (string ...$types): array => array_values(array_filter($objects, static fn (array $o): bool => in_array($o[O::TYPE], $types, true)));

        $plots = $ofType(O::PLOT);
        $plot = [] === $plots ? [] : $metres(Site::points($plots[0]));
        if (count($plot) < 3) {
            return new self(0.0, 0.0, 0, 0, [], []);
        }
        $buildings = array_map(static fn (array $b): array => ['outline' => $metres(Site::points($b)), 'bounds' => Polygon::bounds($metres(Site::points($b)))], $ofType(O::BUILDING));
        $trees = array_map(static function (array $t): array {
            $crown = (float) ($t[O::DIAMETER] ?? 0) / 2;
            $radius = $crown > 0 ? $crown : (float) ($t[O::HEIGHT] ?? 0) * ImpactCalculator::CANOPY_RADIUS_SHARE;

            return [(float) $t[O::X] / self::MILLIMETRES_PER_METRE, (float) $t[O::Z] / self::MILLIMETRES_PER_METRE, $radius / self::MILLIMETRES_PER_METRE + self::ROOT_ZONE_BEYOND_CROWN_METRES];
        }, $ofType(O::TREE, O::NEWTREE));
        $paths = array_values(array_filter(array_map(static function (array $r) use ($metres): array {
            $line = $metres(Site::points($r));

            return ['line' => $line, 'reach' => (float) ($r[O::WIDTH] ?? 0) / 2 / self::MILLIMETRES_PER_METRE + self::PATH_MARGIN_METRES, 'bounds' => count($line) >= 2 ? Polygon::bounds($line) : null];
        }, $ofType(O::ROAD)), static fn (array $p): bool => null !== $p['bounds']));
        $planned = array_values(array_filter(
            array_map(static fn (array $o): array => ['outline' => $metres(Site::points($o)), 'bounds' => count(Site::points($o)) >= 3 ? Polygon::bounds($metres(Site::points($o))) : null], $ofType(O::RAINGARDEN, O::PERMEABLE, O::PLANTING)),
            static fn (array $o): bool => null !== $o['bounds'],
        ));
        $equipment = array_map(static fn (array $o): array => $metres(Site::outlinesOf($o)[0]), $ofType(O::EQUIPMENT));
        $playAreas = array_map(
            static fn (array $o): array => $metres(Site::points($o)),
            array_filter($ofType(O::AREA), static fn (array $o): bool => in_array($o[O::USE] ?? null, self::PLAY_USES, true) && count(Site::points($o)) >= 3),
        );

        [$minX, $maxX, $minZ, $maxZ] = Polygon::bounds($plot);
        $columns = (int) ceil(($maxX - $minX) / self::CELL_METRES);
        $rows = (int) ceil(($maxZ - $minZ) / self::CELL_METRES);
        $blocked = [];
        $distance = [];
        for ($r = 0; $r < $rows; ++$r) {
            for ($c = 0; $c < $columns; ++$c) {
                $x = $minX + ($c + 0.5) * self::CELL_METRES;
                $z = $minZ + ($r + 0.5) * self::CELL_METRES;
                $wall = self::wallDistance($buildings, $x, $z);
                $distance[] = $wall;
                $blocked[] = match (true) {
                    !Polygon::contains($plot, $x, $z) => self::OUTSIDE,
                    0.0 === $wall => self::BUILDING,
                    self::edgeDistance($plot, $x, $z, true) < self::PLOT_MARGIN_METRES => self::PLOT_EDGE,
                    self::inAny($paths, static fn (array $p): bool => self::near($p['bounds'], $x, $z, $p['reach']) && self::edgeDistance($p['line'], $x, $z, false) < $p['reach']) => self::PATH,
                    // before the trees: paving under a tree's crown must not cover a fall zone either
                    self::inAny($equipment, static fn (array $o): bool => Polygon::contains($o, $x, $z)) => self::EQUIPMENT,
                    self::inAny($trees, static fn (array $t): bool => hypot($t[0] - $x, $t[1] - $z) < $t[2]) => self::ROOT_ZONE,
                    self::inAny($planned, static fn (array $o): bool => self::near($o['bounds'], $x, $z, self::PLANNED_MARGIN_METRES) && (Polygon::contains($o['outline'], $x, $z) || self::edgeDistance($o['outline'], $x, $z, true) < self::PLANNED_MARGIN_METRES)) => self::PLANNED,
                    self::inAny($playAreas, static fn (array $o): bool => Polygon::contains($o, $x, $z)) => self::PLAY_AREA,
                    default => null,
                };
            }
        }

        return new self($minX, $minZ, $columns, $rows, $blocked, $distance);
    }

    /** the cell at plan metres x/z, or null off the grid */
    public function cellAt(float $x, float $z): ?int
    {
        $c = (int) floor(($x - $this->originX) / self::CELL_METRES);
        $r = (int) floor(($z - $this->originZ) / self::CELL_METRES);

        return $c >= 0 && $r >= 0 && $c < $this->columns && $r < $this->rows ? $r * $this->columns + $c : null;
    }

    /**
     * @return array{0: float, 1: float} the cell's centre in plan metres
     */
    public function centre(int $cell): array
    {
        return [$this->originX + ($cell % $this->columns + 0.5) * self::CELL_METRES, $this->originZ + (intdiv($cell, $this->columns) + 0.5) * self::CELL_METRES];
    }

    public function cellCount(): int
    {
        return count($this->blocked);
    }

    /**
     * @param list<array{bounds: array{0: float, 1: float, 2: float, 3: float}, outline: list<array{0: float, 1: float}>}> $buildings
     */
    private static function wallDistance(array $buildings, float $x, float $z): float
    {
        $nearest = self::FAR_FROM_BUILDINGS_METRES;
        foreach ($buildings as $building) {
            [$minX, $maxX, $minZ, $maxZ] = $building['bounds'];
            if ($x < $minX - $nearest || $x > $maxX + $nearest || $z < $minZ - $nearest || $z > $maxZ + $nearest || count($building['outline']) < 3) {
                continue;
            }
            if (Polygon::contains($building['outline'], $x, $z)) {
                return 0.0;
            }
            $nearest = min($nearest, self::edgeDistance($building['outline'], $x, $z, true));
        }

        return $nearest;
    }

    /**
     * Distance from a point to an outline's edges (closed) or a line's segments (open).
     *
     * @param list<array{0: float, 1: float}> $points
     */
    private static function edgeDistance(array $points, float $x, float $z, bool $closed): float
    {
        $nearest = INF;
        $count = count($points);
        for ($i = 0; $i < ($closed ? $count : $count - 1); ++$i) {
            [$ax, $az] = $points[$i];
            [$bx, $bz] = $points[($i + 1) % $count];
            [$dx, $dz] = [$bx - $ax, $bz - $az];
            $length = $dx * $dx + $dz * $dz;
            $t = $length > 0 ? max(0.0, min(1.0, (($x - $ax) * $dx + ($z - $az) * $dz) / $length)) : 0.0;
            $nearest = min($nearest, hypot($x - ($ax + $t * $dx), $z - ($az + $t * $dz)));
        }

        return $nearest;
    }

    /**
     * Whether a point lies within $margin of a box (minX, maxX, minZ, maxZ): farther, nothing in it is nearer.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $bounds
     */
    private static function near(array $bounds, float $x, float $z, float $margin): bool
    {
        return $x >= $bounds[0] - $margin && $x <= $bounds[1] + $margin && $z >= $bounds[2] - $margin && $z <= $bounds[3] + $margin;
    }

    /**
     * @template T
     *
     * @param list<T>           $items
     * @param callable(T): bool $test
     */
    private static function inAny(array $items, callable $test): bool
    {
        foreach ($items as $item) {
            if ($test($item)) {
                return true;
            }
        }

        return false;
    }
}

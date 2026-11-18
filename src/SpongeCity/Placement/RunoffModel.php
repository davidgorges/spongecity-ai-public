<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use App\SpongeCity\Terrain\Terrain;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;

/**
 * Where surface water runs on a plot: every node of the terrain's fine grid (2 m) inside the
 * plot drains to its steepest lower neighbour (D8), around buildings, not through them. A node
 * with no lower neighbour is a sink, where water collects; one whose steepest neighbour lies
 * off the plot sends its water away. Each node of open sealed ground adds its area, so a node's
 * accumulation is the sealed area upstream of it.
 *
 * Planning level, like the terrain model: kerbs, gutters and drains are below its resolution,
 * and roofs are assumed to drain through their downpipes, not onto the ground.
 */
final readonly class RunoffModel
{
    private const int SINK = -1;
    private const int AWAY = -2;
    private const array NEIGHBOURS = [[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]];
    private const float MILLIMETRES_PER_METRE = 1000.0;

    /**
     * @param list<array{0: float, 1: float}> $positions    per node, plan metres
     * @param list<float>                     $heights      per node, metres
     * @param list<int>                       $receivers    per node: the node it drains to, SINK or AWAY
     * @param list<float>                     $contribution per node: square metres of sealed ground it adds
     * @param list<float>                     $accumulation per node: square metres of sealed ground draining through it
     * @param array<int, true>                $caught       nodes inside a planned rain garden
     */
    private function __construct(
        public float $cellArea,
        public array $positions,
        public array $heights,
        public array $receivers,
        public array $contribution,
        public array $accumulation,
        private array $caught,
    ) {
    }

    /**
     * @param list<array{0: float, 1: float}>       $plot      outline in plan metres
     * @param list<list<array{0: float, 1: float}>> $buildings footprints in plan metres
     * @param list<list<array{0: float, 1: float}>> $unsealed  outlines in plan metres where the ground takes the rain (planned interventions)
     * @param list<list<array{0: float, 1: float}>> $catchers  outlines in plan metres that catch what runs into them (planned rain gardens)
     */
    public static function of(Terrain $terrain, array $plot, array $buildings, array $unsealed, array $catchers, bool $plotIsSealed): self
    {
        $grid = $terrain->fine;
        $index = [];
        $positions = [];
        $heights = [];
        $grids = [];
        for ($r = 0; $r < $grid->rows; ++$r) {
            for ($c = 0; $c < $grid->columns; ++$c) {
                [$x, $z] = [$grid->originX + $c * $grid->spacing, $grid->originZ + $r * $grid->spacing];
                if (!Polygon::contains($plot, $x, $z) || self::inAny($buildings, $x, $z)) {
                    continue;
                }
                $index[$r * $grid->columns + $c] = count($positions);
                $positions[] = [$x, $z];
                $heights[] = $grid->node($c, $r);
                $grids[] = [$c, $r];
            }
        }
        $cellArea = $grid->spacing ** 2;

        $receivers = [];
        $contribution = [];
        $caught = [];
        foreach ($grids as $n => [$c, $r]) {
            $best = self::SINK;
            $steepest = 0.0;
            foreach (self::NEIGHBOURS as [$dc, $dr]) {
                [$nc, $nr] = [$c + $dc, $r + $dr];
                if ($nc < 0 || $nr < 0 || $nc >= $grid->columns || $nr >= $grid->rows) {
                    continue;
                }
                [$x, $z] = [$grid->originX + $nc * $grid->spacing, $grid->originZ + $nr * $grid->spacing];
                $neighbour = $index[$nr * $grid->columns + $nc] ?? null;
                if (null === $neighbour && self::inAny($buildings, $x, $z)) {
                    continue; // a wall: the water runs along it, not through
                }
                $slope = ($heights[$n] - $grid->node($nc, $nr)) / ($grid->spacing * hypot($dc, $dr));
                if ($slope > $steepest) {
                    $steepest = $slope;
                    $best = $neighbour ?? self::AWAY;
                }
            }
            // a rain garden keeps what reaches it: nothing runs on from there
            $receivers[] = self::inAny($catchers, $positions[$n][0], $positions[$n][1]) ? self::SINK : $best;
            if (self::SINK === $receivers[$n] && self::inAny($catchers, $positions[$n][0], $positions[$n][1])) {
                $caught[$n] = true;
            }
            $contribution[] = $plotIsSealed && !self::inAny($unsealed, $positions[$n][0], $positions[$n][1]) ? $cellArea : 0.0;
        }

        $lowToHigh = array_keys($heights);
        usort($lowToHigh, static fn (int $a, int $b): int => $heights[$a] <=> $heights[$b]);
        $accumulation = $contribution;
        foreach (array_reverse($lowToHigh) as $n) {
            if ($receivers[$n] >= 0) {
                $accumulation[$receivers[$n]] += $accumulation[$n];
            }
        }

        return new self($cellArea, $positions, $heights, $receivers, $contribution, $accumulation, $caught);
    }

    /**
     * The sealed area (m²) whose runoff reaches an outline (plan millimetres): every node inside
     * it, and every node whose water passes through one of them. An outline too small to hold a
     * node takes the nearest one.
     *
     * @param list<array{0: float, 1: float}> $outline
     */
    public function catchmentOf(array $outline): float
    {
        $metres = array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], $outline);

        return $this->catchmentOfNodes($this->nodesIn($metres));
    }

    /**
     * The sealed area (m²) draining into a set of nodes: the accumulation of each node whose water
     * does not pass through another one of them further down (that one already counts it).
     * Quick enough to score every shape tried.
     *
     * @param list<int> $nodes
     */
    public function catchmentOfNodes(array $nodes): float
    {
        $set = array_fill_keys($nodes, true);
        $area = 0.0;
        foreach ($nodes as $node) {
            $downstream = $this->receivers[$node];
            $counted = false;
            for ($guard = count($this->positions); $downstream >= 0 && $guard-- > 0; $downstream = $this->receivers[$downstream]) {
                if (isset($set[$downstream])) {
                    $counted = true;
                    break;
                }
            }
            if (!$counted) {
                $area += $this->accumulation[$node];
            }
        }

        return $area;
    }

    /**
     * Nodes inside an outline in plan metres; the nearest to its centre when none is.
     *
     * @param list<array{0: float, 1: float}> $outline
     *
     * @return list<int>
     */
    public function nodesIn(array $outline): array
    {
        if (count($outline) < 3 || [] === $this->positions) {
            return [];
        }
        [$minX, $maxX, $minZ, $maxZ] = Polygon::bounds($outline);
        $inside = [];
        foreach ($this->positions as $n => [$x, $z]) {
            if ($x >= $minX && $x <= $maxX && $z >= $minZ && $z <= $maxZ && Polygon::contains($outline, $x, $z)) {
                $inside[] = $n;
            }
        }
        if ([] !== $inside) {
            return $inside;
        }
        $cx = ($minX + $maxX) / 2;
        $cz = ($minZ + $maxZ) / 2;
        $distances = array_map(static fn (array $p): float => hypot($p[0] - $cx, $p[1] - $cz), $this->positions);

        return [(int) array_search(min($distances), $distances, true)];
    }

    /**
     * Node indices by accumulation, largest first: where most water passes.
     *
     * @return list<int>
     */
    public function byAccumulation(): array
    {
        $order = array_keys($this->accumulation);
        usort($order, fn (int $a, int $b): int => $this->accumulation[$b] <=> $this->accumulation[$a]);

        return $order;
    }

    /** whether a node's water already ends in a planned rain garden */
    public function isCaught(int $node): bool
    {
        return $this->drainsInto($node, $this->caught);
    }

    /**
     * Whether a node's water passes through any of the given nodes on its way down.
     *
     * @param array<int, true> $nodes
     */
    public function drainsInto(int $node, array $nodes): bool
    {
        for ($n = $node, $guard = count($this->positions); $n >= 0 && $guard-- > 0; $n = $this->receivers[$n]) {
            if (isset($nodes[$n])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<list<array{0: float, 1: float}>> $outlines
     */
    private static function inAny(array $outlines, float $x, float $z): bool
    {
        foreach ($outlines as $outline) {
            if (count($outline) >= 3 && Polygon::contains($outline, $x, $z)) {
                return true;
            }
        }

        return false;
    }
}

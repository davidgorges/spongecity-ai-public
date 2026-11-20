<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use App\SpongeCity\Conditions\InfiltrationPotential;
use App\SpongeCity\Terrain\TerrainAnalysis;
use Core\Configurator\Product\SpongeCity\Impact\GroundClass;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\SiteObject as O;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Rain gardens where water runs: the terrain decides where the sealed ground drains to, the
 * candidates sit on those flow paths, as low as the rules allow, sized to what reaches them, and
 * shaped like a basin the ground would hold: grown from the flow path over the free ground, its
 * outline rounded (CellOutline), not a rectangle.
 *
 * The rules (assumptions(), with their sources) rule out ground before anything is scored:
 * buildings and the clearance from their walls (wider where the garden infiltrates, since its
 * water must not reach a cellar), tree root zones, paths, the plot edge, other interventions and
 * low spots that are probably built. Nothing is placed where water does not come.
 */
#[AsTaggedItem(index: O::RAINGARDEN)]
final readonly class RainGardenSiting implements InterventionSiting
{
    /**
     * DWA-A 138: an infiltrating facility keeps at least 1.5 times the depth of a cellar that is not
     * waterproofed from the building; with cellars unknown, about 6 m.
     */
    public const float INFILTRATING_CLEARANCE_METRES = 6.0;
    /** a lined garden with an overflow gives no water to the ground: room for the edge and the access */
    public const float LINED_CLEARANCE_METRES = 2.0;
    /** sized to about this share of the sealed area that drains into it (the prompt's at least 10 %) */
    public const float SIZE_SHARE_OF_CATCHMENT = 0.12;
    public const float MIN_AREA_M2 = 8.0;
    public const float MAX_AREA_M2 = 150.0;
    /** less sealed ground than this draining to a spot: not worth a rain garden there */
    public const float MIN_CATCHMENT_M2 = 60.0;
    /** the ponding depth a candidate gets (SiteType's default for a rain garden) */
    public const int PONDING_MM = 300;
    /** a gravel trench below a garden whose basin alone does not hold its catchment's runoff: between these depths, in steps */
    public const int MIN_TRENCH_MM = 300;
    public const int MAX_TRENCH_MM = 1500;
    private const int TRENCH_STEP_MM = 100;
    /** candidates further apart than this, so they serve different parts of the plot */
    private const float MIN_SPACING_METRES = 12.0;
    /** with a point given, only flow paths within this distance of it */
    private const float NEAR_RADIUS_METRES = 30.0;
    /** within this of a low spot, the candidate sits in it */
    private const float LOW_SPOT_REACH_METRES = 4.0;
    /**
     * The basin fills from its seed like water would, lowest ground first; each metre away from the
     * seed counts as this much higher, so it stays compact on flat ground and follows the contour
     * (across the flow) on a slope, instead of running out in tongues.
     */
    private const float COMPACTNESS_METRES_PER_METRE = 0.03;
    private const array SHRINK = [1.0, 0.75, 0.5, 0.35];
    /** when the smoothed edge strays onto ruled-out ground, the basin gives up the cells beside it this often */
    private const int REPAIRS = 6;
    /** grown again this often when it came out smaller than this share of the wanted area */
    private const int TOP_UPS = 3;
    private const float TOP_UP_BELOW = 0.95;
    private const int CLEAN_PASSES = 6;
    /** sized again to what the shape catches, at most this often, when that asks for this much more */
    private const int RESIZES = 2;
    private const float RESIZE_ABOVE = 1.2;
    private const float SAMPLE_METRES = 0.5;
    /** flow paths tried at most, largest first */
    private const int MAX_SEEDS = 400;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const string ID_PREFIX = 'raingarden-';

    public function propose(PlacementContext $context, PlacementRequest $request): Proposal
    {
        $excluded = $this->excluded($context);
        if (null === $context->runoff) {
            return new Proposal(O::RAINGARDEN, [], $excluded, $this->assumptions(), 'No terrain model for this plan (outside Hamburg, or imported before terrain was used): without it there is no telling where water runs, so no rain garden is proposed. Place one by hand with add_objects.');
        }
        $runoff = $context->runoff;
        $allowed = $this->allowedCells($context);
        $candidates = [];
        /** @var array<int, true> $taken nodes inside a chosen candidate */
        $taken = [];
        $tried = 0;
        foreach ($runoff->byAccumulation() as $seed) {
            if (count($candidates) >= $request->count || $tried >= self::MAX_SEEDS) {
                break;
            }
            $accumulation = $runoff->accumulation[$seed];
            if ($accumulation < self::MIN_CATCHMENT_M2) {
                break;
            }
            [$sx, $sz] = $runoff->positions[$seed];
            if (null !== $request->near && hypot($sx - $request->near[0] / self::MILLIMETRES_PER_METRE, $sz - $request->near[1] / self::MILLIMETRES_PER_METRE) > self::NEAR_RADIUS_METRES) {
                continue;
            }
            // upstream of a planned or chosen garden its water is already caught; beside one, it serves the same ground
            if ($runoff->isCaught($seed) || $runoff->drainsInto($seed, $taken) || $this->near($candidates, $sx, $sz)) {
                continue;
            }
            ++$tried;
            $cell = $context->ground->cellAt($sx, $sz);
            if (null === $cell || !$allowed[$cell]) {
                continue; // the basin grows from its seed: none on ruled-out ground
            }
            $fit = $this->shape($context, $allowed, $cell, $request->areaM2 ?? $accumulation * self::SIZE_SHARE_OF_CATCHMENT);
            if (null === $fit) {
                continue;
            }
            // a shape across the flow catches more than the seed's own path: size it to that
            for ($resize = 0; null === $request->areaM2 && $resize < self::RESIZES; ++$resize) {
                $wanted = min(self::MAX_AREA_M2, $runoff->catchmentOfNodes($runoff->nodesIn($fit['metres'])) * self::SIZE_SHARE_OF_CATCHMENT);
                if ($wanted <= $fit['area'] * self::RESIZE_ABOVE) {
                    break;
                }
                $larger = $this->shape($context, $allowed, $cell, $wanted);
                if (null === $larger || $larger['area'] <= $fit['area']) {
                    break;
                }
                $fit = $larger;
            }
            $candidate = $this->candidate($context, self::ID_PREFIX.(count($candidates) + 1), $fit, $request);
            if (null === $candidate) {
                continue;
            }
            $candidates[] = $candidate;
            foreach ($runoff->nodesIn($fit['metres']) as $n) {
                $taken[$n] = true;
            }
        }

        $note = match (true) {
            [] !== $candidates => null,
            null !== $request->near => sprintf('No place for a rain garden within %d m of the point: no flow path there with at least %d m² of sealed ground draining to it on free ground. Try without a point, or a smaller area.', (int) self::NEAR_RADIUS_METRES, (int) self::MIN_CATCHMENT_M2),
            default => 'No place for a rain garden on this plot: where water collects, the ground is built, too close to a building, under a tree or a path (see excluded_share_of_plot). Say so rather than placing one where it does not work.',
        };

        return new Proposal(O::RAINGARDEN, $candidates, $excluded, $this->assumptions(), $note);
    }

    public function stillFits(PlacementContext $context, Candidate $candidate): ?string
    {
        $metres = array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], $candidate->object[O::POINTS]);

        return $this->allowed($context->ground, $this->allowedCells($context), $metres) ? null : 'The plan changed since this candidate was proposed: something now stands where it would go. Propose again.';
    }

    /**
     * The basin around a seed cell: grown over allowed ground the way water fills it, traced and
     * smoothed into a soft outline that keeps to allowed ground. At the wanted size, else smaller.
     *
     * @param list<bool> $allowed per open-ground cell
     *
     * @return array{metres: list<array{0: float, 1: float}>, area: float, seed: int}|null
     */
    private function shape(PlacementContext $context, array $allowed, int $seed, float $wanted): ?array
    {
        $ground = $context->ground;
        $cellArea = OpenGround::CELL_METRES ** 2;
        $target = max(self::MIN_AREA_M2, min(self::MAX_AREA_M2, $wanted));
        foreach (self::SHRINK as $shrink) {
            $wantedHere = $target * $shrink;
            $count = (int) round($wantedHere / $cellArea);
            if ($count * $cellArea < self::MIN_AREA_M2 && $shrink < 1.0) {
                break;
            }
            $best = null;
            // the rounded, repaired outline comes out a little smaller than its cells: grow by the shortfall
            for ($topUp = 0; $topUp <= self::TOP_UPS; ++$topUp) {
                $basin = $this->basin($context, $allowed, $seed, $count);
                if (null !== $basin && (null === $best || $basin['area'] > $best['area'])) {
                    $best = $basin;
                }
                if (null === $basin || $basin['area'] >= $wantedHere * self::TOP_UP_BELOW || $basin['cells'] < $count) {
                    break; // big enough, or the free ground around the seed is used up
                }
                $count = (int) ceil($count * $wantedHere / $basin['area']);
            }
            if (null !== $best) {
                return ['metres' => $best['metres'], 'area' => $best['area'], 'seed' => $seed];
            }
        }

        return null;
    }

    /**
     * One basin of a number of cells around the seed: grown, traced, and where its rounded edge
     * strays onto ruled-out ground, the cells there given up and traced again.
     *
     * @param list<bool> $allowed
     *
     * @return array{metres: list<array{0: float, 1: float}>, area: float, cells: int}|null cells: how many were grown
     */
    private function basin(PlacementContext $context, array $allowed, int $seed, int $count): ?array
    {
        $ground = $context->ground;
        $cells = $this->grow($context, $allowed, $seed, $count);
        $grown = count($cells);
        for ($try = 0; $try <= self::REPAIRS && [] !== $cells; ++$try) {
            $outline = CellOutline::of($ground, $cells);
            $area = [] === $outline ? 0.0 : Polygon::area($outline);
            if ($area < self::MIN_AREA_M2) {
                return null;
            }
            $strays = $this->strays($ground, $allowed, $outline);
            if ([] === $strays) {
                return ['metres' => $outline, 'area' => $area, 'cells' => $grown];
            }
            $cells = $this->clean($ground, $allowed, $this->withoutAround($ground, $cells, $strays), $seed);
        }

        return null;
    }

    /**
     * Fills allowed cells from the seed, lowest (and nearest) first, up to a number of cells; a set
     * without holes or corner-only contacts, so it has one clean outline.
     *
     * @param list<bool> $allowed
     *
     * @return array<int, true>
     */
    private function grow(PlacementContext $context, array $allowed, int $seed, int $count): array
    {
        $ground = $context->ground;
        $terrain = $context->terrain;
        [$sx, $sz] = $ground->centre($seed);
        $cost = static function (int $cell) use ($ground, $terrain, $sx, $sz): float {
            [$x, $z] = $ground->centre($cell);

            return (null === $terrain ? 0.0 : $terrain->heightAt($x, $z)) + self::COMPACTNESS_METRES_PER_METRE * hypot($x - $sx, $z - $sz);
        };
        $queue = new \SplPriorityQueue();
        $queue->setExtractFlags(\SplPriorityQueue::EXTR_DATA);
        $queued = [$seed => true];
        $order = 0;
        $queue->insert($seed, [-$cost($seed), $order]);
        $cells = [];
        while (count($cells) < $count && !$queue->isEmpty()) {
            $cell = $queue->extract();
            $cells[$cell] = true;
            foreach ($this->neighbours($ground, $cell) as $next) {
                if (!isset($queued[$next]) && $allowed[$next]) {
                    $queued[$next] = true;
                    $queue->insert($next, [-$cost($next), --$order]);
                }
            }
        }

        return $this->clean($ground, $allowed, $cells, $seed);
    }

    /**
     * Makes a cell set one clean shape: enclosed gaps filled where allowed (else the cells around
     * them given up), corner-only contacts joined or cut, only the part connected to the seed.
     *
     * @param list<bool>       $allowed
     * @param array<int, true> $cells
     *
     * @return array<int, true>
     */
    private function clean(OpenGround $ground, array $allowed, array $cells, int $seed): array
    {
        for ($pass = 0; $pass < self::CLEAN_PASSES && [] !== $cells; ++$pass) {
            $changed = false;
            foreach ($this->holes($ground, $cells) as $hole) {
                $changed = true;
                if ([] === array_filter($hole, static fn (int $c): bool => !$allowed[$c])) {
                    foreach ($hole as $c) {
                        $cells[$c] = true;
                    }
                    continue;
                }
                // a tree's root zone or a path inside: open the ring around it instead
                foreach ($hole as $c) {
                    foreach ($this->neighbours($ground, $c) as $n) {
                        unset($cells[$n]);
                    }
                }
            }
            $columns = $ground->columns;
            foreach (array_keys($cells) as $cell) {
                [$c, $r] = [$cell % $columns, intdiv($cell, $columns)];
                foreach ([-1, 1] as $dc) {
                    $diagonal = $r + 1 < $ground->rows && $c + $dc >= 0 && $c + $dc < $columns ? ($r + 1) * $columns + $c + $dc : null;
                    $side = $r * $columns + $c + $dc;
                    $below = ($r + 1) * $columns + $c;
                    if (null === $diagonal || !isset($cells[$diagonal]) || isset($cells[$side]) || isset($cells[$below])) {
                        continue;
                    }
                    $changed = true;
                    if ($allowed[$side]) {
                        $cells[$side] = true;
                    } elseif ($allowed[$below]) {
                        $cells[$below] = true;
                    } else {
                        unset($cells[$diagonal]);
                    }
                }
            }
            if (!$changed) {
                break;
            }
        }

        return $this->connected($ground, $cells, $seed);
    }

    /**
     * Gaps enclosed by the set: every run of cells outside it that the border of its frame cannot reach.
     *
     * @param array<int, true> $cells
     *
     * @return list<list<int>>
     */
    private function holes(OpenGround $ground, array $cells): array
    {
        if ([] === $cells) {
            return [];
        }
        $columns = $ground->columns;
        $cs = array_map(static fn (int $cell): int => $cell % $columns, array_keys($cells));
        $rs = array_map(static fn (int $cell): int => intdiv($cell, $columns), array_keys($cells));
        [$minC, $maxC, $minR, $maxR] = [min($cs), max($cs), min($rs), max($rs)];
        $outside = [];
        $stack = [];
        for ($r = $minR; $r <= $maxR; ++$r) {
            for ($c = $minC; $c <= $maxC; ++$c) {
                $cell = $r * $columns + $c;
                if (!isset($cells[$cell]) && ($r === $minR || $r === $maxR || $c === $minC || $c === $maxC)) {
                    $outside[$cell] = true;
                    $stack[] = $cell;
                }
            }
        }
        $inFrame = static fn (int $cell): bool => $cell % $columns >= $minC && $cell % $columns <= $maxC && intdiv($cell, $columns) >= $minR && intdiv($cell, $columns) <= $maxR;
        while ([] !== $stack) {
            foreach ($this->neighbours($ground, array_pop($stack)) as $n) {
                if ($inFrame($n) && !isset($cells[$n]) && !isset($outside[$n])) {
                    $outside[$n] = true;
                    $stack[] = $n;
                }
            }
        }
        $holes = [];
        $seen = [];
        for ($r = $minR; $r <= $maxR; ++$r) {
            for ($c = $minC; $c <= $maxC; ++$c) {
                $cell = $r * $columns + $c;
                if (isset($cells[$cell]) || isset($outside[$cell]) || isset($seen[$cell])) {
                    continue;
                }
                $hole = [];
                $stack = [$cell];
                $seen[$cell] = true;
                while ([] !== $stack) {
                    $hole[] = $current = array_pop($stack);
                    foreach ($this->neighbours($ground, $current) as $n) {
                        if (!isset($cells[$n]) && !isset($outside[$n]) && !isset($seen[$n])) {
                            $seen[$n] = true;
                            $stack[] = $n;
                        }
                    }
                }
                $holes[] = $hole;
            }
        }

        return $holes;
    }

    /**
     * The set without its cells next to (or on) the given cells.
     *
     * @param array<int, true> $cells
     * @param list<int>        $around
     *
     * @return array<int, true>
     */
    private function withoutAround(OpenGround $ground, array $cells, array $around): array
    {
        $columns = $ground->columns;
        foreach ($around as $cell) {
            [$c, $r] = [$cell % $columns, intdiv($cell, $columns)];
            for ($dr = -1; $dr <= 1; ++$dr) {
                for ($dc = -1; $dc <= 1; ++$dc) {
                    if ($c + $dc >= 0 && $c + $dc < $columns && $r + $dr >= 0 && $r + $dr < $ground->rows) {
                        unset($cells[($r + $dr) * $columns + $c + $dc]);
                    }
                }
            }
        }

        return $cells;
    }

    /**
     * The part of the set connected (side by side) to the seed, or to its first cell when the seed is gone.
     *
     * @param array<int, true> $cells
     *
     * @return array<int, true>
     */
    private function connected(OpenGround $ground, array $cells, int $seed): array
    {
        if ([] === $cells) {
            return [];
        }
        $start = isset($cells[$seed]) ? $seed : (int) array_key_first($cells);
        $kept = [$start => true];
        $stack = [$start];
        while ([] !== $stack) {
            foreach ($this->neighbours($ground, array_pop($stack)) as $n) {
                if (isset($cells[$n]) && !isset($kept[$n])) {
                    $kept[$n] = true;
                    $stack[] = $n;
                }
            }
        }

        return $kept;
    }

    /**
     * @return list<int> the cells sharing a side with a cell
     */
    private function neighbours(OpenGround $ground, int $cell): array
    {
        $columns = $ground->columns;
        [$c, $r] = [$cell % $columns, intdiv($cell, $columns)];
        $neighbours = [];
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dc, $dr]) {
            if ($c + $dc >= 0 && $c + $dc < $columns && $r + $dr >= 0 && $r + $dr < $ground->rows) {
                $neighbours[] = ($r + $dr) * $columns + $c + $dc;
            }
        }

        return $neighbours;
    }

    /**
     * Whether every part of an outline (plan metres) lies on cells a rain garden may take.
     *
     * @param list<bool>                      $allowed per open-ground cell
     * @param list<array{0: float, 1: float}> $outline
     */
    private function allowed(OpenGround $ground, array $allowed, array $outline): bool
    {
        return [] !== $outline && [] === $this->strays($ground, $allowed, $outline);
    }

    /**
     * The ruled-out cells an outline touches (by its corners, edges and a raster inside).
     *
     * @param list<bool>                      $allowed per open-ground cell
     * @param list<array{0: float, 1: float}> $outline
     *
     * @return list<int>
     */
    private function strays(OpenGround $ground, array $allowed, array $outline): array
    {
        $strays = [];
        foreach ($this->samples($outline) as [$x, $z]) {
            $cell = $ground->cellAt($x, $z);
            if (null === $cell) {
                // off the grid: the plot's edge is near, its outermost cell stands for it
                $cell = $ground->cellAt(max($ground->originX, min($x, $ground->originX + $ground->columns - 0.5)), max($ground->originZ, min($z, $ground->originZ + $ground->rows - 0.5)));
            }
            if (null !== $cell && !$allowed[$cell]) {
                $strays[$cell] = true;
            }
        }

        return array_keys($strays);
    }

    /**
     * Which cells of the open ground a rain garden may take: no other rule rules them out, they
     * keep the clearance from buildings their infiltration needs, and no built low spot is near.
     *
     * @return list<bool>
     */
    private function allowedCells(PlacementContext $context): array
    {
        $ground = $context->ground;
        $built = array_values(array_filter($context->lowSpots, static fn (array $s): bool => TerrainAnalysis::KIND_BUILT === $s['kind']));
        $allowed = [];
        for ($cell = 0, $n = $ground->cellCount(); $cell < $n; ++$cell) {
            $allowed[] = null === $ground->blocked[$cell] && $this->clearOfBuildings($context, $cell) && !$this->nearAny($ground, $built, $cell);
        }

        return $allowed;
    }

    private function clearOfBuildings(PlacementContext $context, int $cell): bool
    {
        $distance = $context->ground->buildingDistance[$cell];
        if ($distance >= max(self::INFILTRATING_CLEARANCE_METRES, self::LINED_CLEARANCE_METRES)) {
            return true; // far enough whatever the ground (spares reading the map)
        }

        return $distance >= $this->clearance($context->infiltrationAt($cell));
    }

    /**
     * @param list<array<string, mixed>> $spots
     */
    private function nearAny(OpenGround $ground, array $spots, int $cell): bool
    {
        [$x, $z] = $ground->centre($cell);
        foreach ($spots as $spot) {
            if (hypot($spot['x'] / self::MILLIMETRES_PER_METRE - $x, $spot['z'] / self::MILLIMETRES_PER_METRE - $z) < self::LOW_SPOT_REACH_METRES) {
                return true;
            }
        }

        return false;
    }

    /**
     * Points to check an outline by: its corners, its edges every SAMPLE_METRES, and a raster inside.
     *
     * @param list<array{0: float, 1: float}> $outline
     *
     * @return list<array{0: float, 1: float}>
     */
    private function samples(array $outline): array
    {
        $samples = $outline;
        $count = count($outline);
        foreach ($outline as $i => [$ax, $az]) {
            [$bx, $bz] = $outline[($i + 1) % $count];
            $steps = max(1, (int) ceil(hypot($bx - $ax, $bz - $az) / self::SAMPLE_METRES));
            for ($k = 1; $k < $steps; ++$k) {
                $samples[] = [$ax + ($bx - $ax) * $k / $steps, $az + ($bz - $az) * $k / $steps];
            }
        }
        [$minX, $maxX, $minZ, $maxZ] = Polygon::bounds($outline);
        for ($x = $minX + self::SAMPLE_METRES / 2; $x < $maxX; $x += self::SAMPLE_METRES) {
            for ($z = $minZ + self::SAMPLE_METRES / 2; $z < $maxZ; $z += self::SAMPLE_METRES) {
                if (Polygon::contains($outline, $x, $z)) {
                    $samples[] = [$x, $z];
                }
            }
        }

        return $samples;
    }

    private function clearance(InfiltrationPotential $potential): float
    {
        return $this->infiltrates($potential) ? self::INFILTRATING_CLEARANCE_METRES : self::LINED_CLEARANCE_METRES;
    }

    private function infiltrates(InfiltrationPotential $potential): bool
    {
        return $potential->soaksAway();
    }

    /**
     * @param array{metres: list<array{0: float, 1: float}>, area: float, seed: int} $fit
     */
    private function candidate(PlacementContext $context, string $id, array $fit, PlacementRequest $request): ?Candidate
    {
        $runoff = $context->runoff;
        \assert(null !== $runoff);
        $points = array_map(static fn (array $p): array => [(int) round($p[0] * self::MILLIMETRES_PER_METRE), (int) round($p[1] * self::MILLIMETRES_PER_METRE)], $fit['metres']);
        $catchment = $runoff->catchmentOf($points);
        if ($catchment < self::MIN_CATCHMENT_M2) {
            return null;
        }
        // the seed lies inside, the centre of a curved outline may not
        [$cx, $cz] = $context->ground->centre($fit['seed']);
        $potential = $context->infiltrationAt($fit['seed']);
        $infiltrates = $this->infiltrates($potential);
        $wall = min(array_map(static function (array $p) use ($context): float {
            $c = $context->ground->cellAt($p[0], $p[1]);

            return null === $c ? OpenGround::FAR_FROM_BUILDINGS_METRES : $context->ground->buildingDistance[$c];
        }, $fit['metres']));

        $area = $fit['area'];
        $runoffPerM2 = GroundClass::Asphalt->runoffCoefficient() * $context->stormMillimetres / self::MILLIMETRES_PER_METRE;
        $inflow = $catchment * $runoffPerM2;
        $basin = $area * ImpactCalculator::rainGardenStorageMetres(self::PONDING_MM);
        $trench = $infiltrates ? self::trenchFor($area, $catchment, $runoffPerM2, $basin) : 0;
        $storage = $area * ImpactCalculator::rainGardenStorageMetres(self::PONDING_MM, $trench);
        $ratio = $trench > 0 ? ImpactCalculator::MAX_CATCHMENT_RATIO_TRENCH : ImpactCalculator::MAX_CATCHMENT_RATIO;
        $retained = min($storage, $inflow, $area * $ratio * $runoffPerM2);
        $spot = $this->lowSpotAt($context, $cx, $cz);

        $reasons = [sprintf('Collects the runoff of about %d m² of sealed ground: the terrain (DGM1, 2 m) drains it here.', (int) round($catchment, -1))];
        if (null !== $spot) {
            $reasons[] = sprintf('Sits in a low spot %d cm deep, where the water gathers anyway.', $spot['depth_cm']);
        }
        $reasons[] = $trench > 0
            ? sprintf('Holds %.0f m³ of the %.0f m³ that runs off its catchment in a %d mm/h storm: %d mm ponding and the planting medium (%.0f m³), and a gravel trench %.1f m deep below it (a swale-trench, Mulden-Rigolen-Element: %.0f m³ more, DWA-A 138).', $storage, $inflow, $context->stormMillimetres, self::PONDING_MM, $basin, $trench / self::MILLIMETRES_PER_METRE, $storage - $basin)
            : sprintf('Holds %.0f m³ of the %.0f m³ that runs off its catchment in a %d mm/h storm (%d mm ponding plus the planting medium).', $storage, $inflow, $context->stormMillimetres, self::PONDING_MM);
        $reasons[] = $infiltrates
            ? sprintf('Infiltration is %s here (Versickerungspotentialkarte Hamburg): an infiltrating rain garden, kept %.0f m from the buildings (DWA-A 138).', $potential->value, self::INFILTRATING_CLEARANCE_METRES)
            : sprintf('Infiltration is %s here (Versickerungspotentialkarte Hamburg): a lined rain garden with an overflow, so %.0f m from the buildings is enough.', $potential->value, self::LINED_CLEARANCE_METRES);

        $warnings = [];
        if ($catchment > $area * $ratio) {
            $warnings[] = sprintf('Small for its catchment (%d m² drain into %d m²): the rest overflows; a larger garden did not fit here.', (int) round($catchment, -1), (int) round($area));
        }
        if ($inflow > $storage) {
            $warnings[] = sprintf('Overflows in the design storm (%.0f of %.0f m³ held): plan the overflow (to the sewer or a further garden).', $storage, $inflow);
            if (!$infiltrates) {
                $warnings[] = 'No gravel trench below: it would only store water where the ground lets it soak away, which the map does not say here.';
            }
        }
        if (null !== $request->areaM2 && $area < $request->areaM2 - 0.5) {
            $warnings[] = sprintf('Smaller than asked (%d instead of %d m²): no larger one fits on the flow path here.', (int) round($area), (int) round($request->areaM2));
        }
        if (InfiltrationPotential::Unknown === $potential) {
            $warnings[] = 'The infiltration map has no class here: planned as lined; a soil survey decides.';
        }

        return new Candidate(
            $id,
            [O::TYPE => O::RAINGARDEN, O::POINTS => $points, O::STORAGE => self::PONDING_MM, O::TRENCH => $trench],
            [
                'area_m2' => round($area, 1),
                'catchment_m2' => round($catchment),
                'storage_m3' => round($storage, 1),
                'trench_mm' => $trench,
                'inflow_m3' => round($inflow, 1),
                'retained_m3' => round($retained, 1),
                'infiltration' => $potential->value,
                'lined' => !$infiltrates,
                'distance_to_building_m' => round($wall, 1),
                'low_spot_depth_cm' => $spot['depth_cm'] ?? null,
            ],
            $reasons,
            $warnings,
        );
    }

    /**
     * The depth of a gravel trench a garden needs below it to hold what its catchment sheds, where its
     * basin alone does not: in TRENCH_STEP_MM steps between MIN_TRENCH_MM and MAX_TRENCH_MM, 0 when the
     * basin is enough. A trench lets the garden take a larger catchment (MAX_CATCHMENT_RATIO_TRENCH).
     */
    private static function trenchFor(float $area, float $catchment, float $runoffPerM2, float $basin): int
    {
        $reachable = min($catchment, $area * ImpactCalculator::MAX_CATCHMENT_RATIO_TRENCH) * $runoffPerM2;
        $missing = $reachable - $basin;
        if ($missing <= 0.0 || $area <= 0.0) {
            return 0;
        }
        $depth = $missing / ($area * ImpactCalculator::TRENCH_VOID_SHARE) * self::MILLIMETRES_PER_METRE;
        $stepped = (int) (ceil($depth / self::TRENCH_STEP_MM) * self::TRENCH_STEP_MM);

        return max(self::MIN_TRENCH_MM, min(self::MAX_TRENCH_MM, $stepped));
    }

    /**
     * @return array<string, mixed>|null the hollow the point lies in
     */
    private function lowSpotAt(PlacementContext $context, float $x, float $z): ?array
    {
        foreach ($context->lowSpots as $spot) {
            if (TerrainAnalysis::KIND_HOLLOW === $spot['kind'] && hypot($spot['x'] / self::MILLIMETRES_PER_METRE - $x, $spot['z'] / self::MILLIMETRES_PER_METRE - $z) <= self::LOW_SPOT_REACH_METRES) {
                return $spot;
            }
        }

        return null;
    }

    /**
     * @param list<Candidate> $candidates
     */
    private function near(array $candidates, float $x, float $z): bool
    {
        foreach ($candidates as $candidate) {
            $points = $candidate->object[O::POINTS];
            $cx = array_sum(array_column($points, 0)) / count($points) / self::MILLIMETRES_PER_METRE;
            $cz = array_sum(array_column($points, 1)) / count($points) / self::MILLIMETRES_PER_METRE;
            if (hypot($cx - $x, $cz - $z) < self::MIN_SPACING_METRES) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why the plot's ground is ruled out for a rain garden, as shares of the plot.
     *
     * @return array<string, float>
     */
    private function excluded(PlacementContext $context): array
    {
        $ground = $context->ground;
        $counts = [];
        $inside = 0;
        for ($cell = 0, $n = $ground->cellCount(); $cell < $n; ++$cell) {
            $reason = $ground->blocked[$cell];
            if (OpenGround::OUTSIDE === $reason) {
                continue;
            }
            ++$inside;
            if (null === $reason && !$this->clearOfBuildings($context, $cell)) {
                $reason = 'too close to a building';
            }
            if (null !== $reason) {
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }
        arsort($counts);

        return 0 === $inside ? [] : array_map(static fn (int $c): float => round($c / $inside, 3), $counts);
    }

    /**
     * @return list<string>
     */
    private function assumptions(): array
    {
        return [
            sprintf('Where water runs: steepest descent on the DGM1 terrain model (2 m), around buildings; roofs drain through their downpipes. Kerbs, gutters and drains are not in the model and may send water elsewhere: confirm on site.'),
            'All open ground of the plot counts as sealed (its surface), except planned interventions.',
            sprintf('Infiltrating rain gardens (where the Versickerungspotentialkarte says possible or likely) keep %.0f m from buildings (DWA-A 138: 1.5 times the depth of a cellar that is not waterproofed); lined ones %.0f m.', self::INFILTRATING_CLEARANCE_METRES, self::LINED_CLEARANCE_METRES),
            sprintf('Not in tree root zones (crown plus %.1f m, DIN 18920), on streets or paths, at the plot edge, on other interventions, on play or sports areas, in the fall zones of play equipment (DIN EN 1176) or in low spots that are probably built.', OpenGround::ROOT_ZONE_BEYOND_CROWN_METRES),
            sprintf('Sized to about %d %% of the sealed area that drains into it (%d to %d m²), with %d mm ponding.', (int) round(self::SIZE_SHARE_OF_CATCHMENT * 100), (int) self::MIN_AREA_M2, (int) self::MAX_AREA_M2, self::PONDING_MM),
            sprintf('Where its basin does not hold its catchment\'s runoff and the ground lets water soak away (possible or likely), a gravel trench %.1f to %.1f m deep goes below it (Mulden-Rigolen-Element, DWA-A 138): its pores hold %d %% of its volume, and the garden may take the runoff of %d instead of %d times its area. Lined gardens get no trench.', self::MIN_TRENCH_MM / self::MILLIMETRES_PER_METRE, self::MAX_TRENCH_MM / self::MILLIMETRES_PER_METRE, (int) round(ImpactCalculator::TRENCH_VOID_SHARE * 100), (int) ImpactCalculator::MAX_CATCHMENT_RATIO_TRENCH, (int) ImpactCalculator::MAX_CATCHMENT_RATIO),
        ];
    }
}

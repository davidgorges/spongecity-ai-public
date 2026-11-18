<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use App\SpongeCity\Conditions\InfiltrationPotential;
use App\SpongeCity\Terrain\TerrainAnalysis;
use Core\Configurator\Product\SpongeCity\Impact\GroundClass;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Site\SiteObject as O;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Rain gardens where water runs: the terrain decides where the sealed ground drains to, the
 * candidates sit on those flow paths, as low as the rules allow, sized to what reaches them.
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
    /** candidates further apart than this, so they serve different parts of the plot */
    private const float MIN_SPACING_METRES = 12.0;
    /** with a point given, only flow paths within this distance of it */
    private const float NEAR_RADIUS_METRES = 30.0;
    /** within this of a low spot, the candidate sits in it */
    private const float LOW_SPOT_REACH_METRES = 4.0;
    private const array ANGLES = [0, 30, 60, 90, 120, 150];
    /** up to a long strip across the flow, which catches what runs down a slope side by side */
    private const array ASPECTS = [1.0, 1.6, 2.5, 4.0];
    /** where the seed may lie in the rectangle, as shares of its sides from the centre */
    private const array SEED_OFFSETS = [0.0, -0.3, 0.3];
    private const array SHRINK = [1.0, 0.75, 0.5, 0.35];
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
                continue; // the rectangle holds its seed: no use trying around a seed on ruled-out ground
            }
            $fit = $this->fit($context, $allowed, $sx, $sz, $request->areaM2 ?? $accumulation * self::SIZE_SHARE_OF_CATCHMENT);
            if (null === $fit) {
                continue;
            }
            // a shape across the flow catches more than the seed's own path: size it to that
            for ($resize = 0; null === $request->areaM2 && $resize < self::RESIZES; ++$resize) {
                $wanted = min(self::MAX_AREA_M2, $runoff->catchmentOfNodes($runoff->nodesIn($fit['metres'])) * self::SIZE_SHARE_OF_CATCHMENT);
                if ($wanted <= $fit['area'] * self::RESIZE_ABOVE) {
                    break;
                }
                $larger = $this->fit($context, $allowed, $sx, $sz, $wanted);
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
     * The best rectangle around a seed that fits on allowed ground: largest flow through it, then
     * the most compact. Tried at the wanted size, then smaller.
     *
     * @param list<bool> $allowed per open-ground cell
     *
     * @return array{metres: list<array{0: float, 1: float}>, area: float}|null
     */
    private function fit(PlacementContext $context, array $allowed, float $sx, float $sz, float $wanted): ?array
    {
        $runoff = $context->runoff;
        \assert(null !== $runoff);
        $target = max(self::MIN_AREA_M2, min(self::MAX_AREA_M2, $wanted));
        foreach (self::SHRINK as $shrink) {
            $area = $target * $shrink;
            if ($area < self::MIN_AREA_M2 && $shrink < 1.0) {
                break;
            }
            $best = null;
            foreach (self::ASPECTS as $aspect) {
                $length = sqrt($area * $aspect);
                $width = $area / $length;
                foreach (self::ANGLES as $angle) {
                    [$cos, $sin] = [cos(deg2rad($angle)), sin(deg2rad($angle))];
                    foreach (self::SEED_OFFSETS as $u) {
                        foreach (self::SEED_OFFSETS as $v) {
                            // the seed sits at (u, v) of the rectangle's half sides: its centre is the other way
                            $cx = $sx - ($u * $length * $cos - $v * $width * $sin);
                            $cz = $sz - ($u * $length * $sin + $v * $width * $cos);
                            $corners = [];
                            foreach ([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$a, $b]) {
                                $corners[] = [$cx + $a * $length / 2 * $cos - $b * $width / 2 * $sin, $cz + $a * $length / 2 * $sin + $b * $width / 2 * $cos];
                            }
                            if (!$this->allowed($context->ground, $allowed, $corners)) {
                                continue;
                            }
                            // what really drains into it; then the most compact, the seed nearest its middle
                            $score = $runoff->catchmentOfNodes($runoff->nodesIn($corners)) - $aspect - hypot($u, $v);
                            if (null === $best || $score > $best['score']) {
                                $best = ['metres' => $corners, 'area' => $area, 'score' => $score];
                            }
                        }
                    }
                }
            }
            if (null !== $best) {
                return ['metres' => $best['metres'], 'area' => $best['area']];
            }
        }

        return null;
    }

    /**
     * Whether every part of an outline (plan metres) lies on cells a rain garden may take.
     *
     * @param list<bool>                      $allowed per open-ground cell
     * @param list<array{0: float, 1: float}> $outline
     */
    private function allowed(OpenGround $ground, array $allowed, array $outline): bool
    {
        // the outline's corners first, then a fine raster inside it
        foreach ($this->samples($outline) as [$x, $z]) {
            $cell = $ground->cellAt($x, $z);
            if (null === $cell || !$allowed[$cell]) {
                return false;
            }
        }

        return true;
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
     * @param list<array{0: float, 1: float}> $outline a convex outline (the candidates are rectangles)
     *
     * @return list<array{0: float, 1: float}>
     */
    private function samples(array $outline): array
    {
        $samples = $outline;
        [$a, $b, , $d] = $outline;
        $steps = static fn (array $p, array $q): int => max(1, (int) ceil(hypot($q[0] - $p[0], $q[1] - $p[1]) / self::SAMPLE_METRES));
        [$nu, $nv] = [$steps($a, $b), $steps($a, $d)];
        for ($i = 0; $i <= $nu; ++$i) {
            for ($j = 0; $j <= $nv; ++$j) {
                $samples[] = [$a[0] + ($b[0] - $a[0]) * $i / $nu + ($d[0] - $a[0]) * $j / $nv, $a[1] + ($b[1] - $a[1]) * $i / $nu + ($d[1] - $a[1]) * $j / $nv];
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
        return InfiltrationPotential::Possible === $potential || InfiltrationPotential::Likely === $potential;
    }

    /**
     * @param array{metres: list<array{0: float, 1: float}>, area: float} $fit
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
        $cx = array_sum(array_column($fit['metres'], 0)) / 4;
        $cz = array_sum(array_column($fit['metres'], 1)) / 4;
        $cell = $context->ground->cellAt($cx, $cz);
        \assert(null !== $cell);
        $potential = $context->infiltrationAt($cell);
        $infiltrates = $this->infiltrates($potential);
        $wall = min(array_map(static function (array $p) use ($context): float {
            $c = $context->ground->cellAt($p[0], $p[1]);

            return null === $c ? OpenGround::FAR_FROM_BUILDINGS_METRES : $context->ground->buildingDistance[$c];
        }, $fit['metres']));

        $area = $fit['area'];
        $storage = $area * (self::PONDING_MM / self::MILLIMETRES_PER_METRE + ImpactCalculator::RAIN_GARDEN_MEDIA_STORAGE);
        $inflow = $catchment * GroundClass::Asphalt->runoffCoefficient() * $context->stormMillimetres / self::MILLIMETRES_PER_METRE;
        $retained = min($storage, $inflow);
        $spot = $this->lowSpotAt($context, $cx, $cz);

        $reasons = [sprintf('Collects the runoff of about %d m² of sealed ground: the terrain (DGM1, 2 m) drains it here.', (int) round($catchment, -1))];
        if (null !== $spot) {
            $reasons[] = sprintf('Sits in a low spot %d cm deep, where the water gathers anyway.', $spot['depth_cm']);
        }
        $reasons[] = sprintf('Holds %.0f m³ of the %.0f m³ that runs off its catchment in a %d mm/h storm (%d mm ponding plus the planting medium).', $storage, $inflow, $context->stormMillimetres, self::PONDING_MM);
        $reasons[] = $infiltrates
            ? sprintf('Infiltration is %s here (Versickerungspotentialkarte Hamburg): an infiltrating rain garden, kept %.0f m from the buildings (DWA-A 138).', $potential->value, self::INFILTRATING_CLEARANCE_METRES)
            : sprintf('Infiltration is %s here (Versickerungspotentialkarte Hamburg): a lined rain garden with an overflow, so %.0f m from the buildings is enough.', $potential->value, self::LINED_CLEARANCE_METRES);

        $warnings = [];
        if ($catchment > $area * ImpactCalculator::MAX_CATCHMENT_RATIO) {
            $warnings[] = sprintf('Small for its catchment (%d m² drain into %d m²): the rest overflows; a larger garden did not fit here.', (int) round($catchment, -1), (int) round($area));
        }
        if ($inflow > $storage) {
            $warnings[] = sprintf('Overflows in the design storm (%.0f of %.0f m³ held): plan the overflow (to the sewer or a further garden).', $storage, $inflow);
        }
        if (null !== $request->areaM2 && $area < $request->areaM2 - 0.5) {
            $warnings[] = sprintf('Smaller than asked (%d instead of %d m²): no larger one fits on the flow path here.', (int) round($area), (int) round($request->areaM2));
        }
        if (InfiltrationPotential::Unknown === $potential) {
            $warnings[] = 'The infiltration map has no class here: planned as lined; a soil survey decides.';
        }

        return new Candidate(
            $id,
            [O::TYPE => O::RAINGARDEN, O::POINTS => $points, O::STORAGE => self::PONDING_MM],
            [
                'area_m2' => round($area, 1),
                'catchment_m2' => round($catchment),
                'storage_m3' => round($storage, 1),
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
            sprintf('Not in tree root zones (crown plus %.1f m, DIN 18920), on streets or paths, at the plot edge, on other interventions or in low spots that are probably built.', OpenGround::ROOT_ZONE_BEYOND_CROWN_METRES),
            sprintf('Sized to about %d %% of the sealed area that drains into it (%d to %d m²), with %d mm ponding.', (int) round(self::SIZE_SHARE_OF_CATCHMENT * 100), (int) self::MIN_AREA_M2, (int) self::MAX_AREA_M2, self::PONDING_MM),
        ];
    }
}

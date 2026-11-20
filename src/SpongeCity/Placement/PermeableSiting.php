<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use App\SpongeCity\Conditions\InfiltrationPotential;
use Core\Configurator\Product\SpongeCity\Impact\GroundClass;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Permeable surfaces the way a landscape architect plans them: not patches dropped on a yard, but
 * existing hard surfaces that become another, zone by zone (SurfaceZones). The zone's use decides
 * the material, or that it stays as it is: a car park gets grass pavers, a yard permeable pavers
 * that stay even for running and wheelchairs, quiet edges a water-bound gravel surface; access
 * lanes stay passable, sports and play areas keep their own surfaces.
 *
 * The shapes follow the site's lines: a whole zone, a band along a façade (the middle of a yard
 * stays open for play), or the ground under the trees (whose roots gain air and water).
 */
#[AsTaggedItem(index: O::PERMEABLE)]
final readonly class PermeableSiting implements InterventionSiting
{
    /** use → the permeable kind it takes */
    public const array MATERIALS = [
        O::AREA_YARD => O::KIND_PAVERS,
        O::AREA_PARKING => O::KIND_GRASS_PAVERS,
        O::AREA_QUIET => O::KIND_GRAVEL,
    ];
    /** use → why its surface stays */
    public const array KEPT = [
        O::AREA_ACCESS => 'access lanes stay passable for fire engines and deliveries (DIN 14090)',
        O::AREA_SPORT => 'sports areas keep their own surface',
        O::AREA_PLAY => 'play areas keep their own surfacing (fall protection)',
        O::AREA_GREEN => 'already open ground',
    ];
    /** why ground nobody has described gets no paving */
    public const string UNKNOWN_GROUND = 'ground nobody has described yet (it may be lawn or a garden): ask the user and record it with describe_zone';
    /** use → why its material suits it */
    private const array WHY = [
        O::AREA_YARD => 'permeable pavers with open joints stay even for running and wheelchairs',
        O::AREA_PARKING => 'grass pavers carry cars and let the rain through',
        O::AREA_QUIET => 'a water-bound gravel surface suits ground with little traffic',
    ];
    /** use → why each of the other permeable kinds does not suit it */
    public const array WHY_NOT = [
        O::AREA_YARD => [
            O::KIND_GRASS_PAVERS => 'grass between the stones does not survive daily running and play, and the open cells trip wheelchairs and buggies',
            O::KIND_GRAVEL => 'loose gravel gets kicked about and is hard going for wheelchairs',
        ],
        O::AREA_PARKING => [
            O::KIND_PAVERS => 'pavers carry cars as well, but let less rain through and heat up more, and parked cars leave the grass alone',
            O::KIND_GRAVEL => 'water-bound gravel ruts under turning and parking cars',
        ],
        O::AREA_QUIET => [
            O::KIND_PAVERS => 'pavers are more than ground with little traffic needs, and cost more',
            O::KIND_GRASS_PAVERS => 'grass pavers are built to carry loads: with little traffic gravel does the job for less',
        ],
    ];
    /** why the root zone of trees takes gravel and nothing else */
    public const string WHY_NOT_IN_ROOT_ZONE = 'it needs a compacted base dug into the root zone';
    /** a band along a façade, metres deep (from the drip strip); deeper when an area is asked for */
    public const float BAND_METRES = 6.0;
    private const float MAX_BAND_METRES = 20.0;
    private const float BAND_STEP_METRES = 1.0;
    /** a candidate smaller than this is not worth building */
    public const float MIN_AREA_M2 = 20.0;
    /** permeable paving wants gentle ground: above this the base must be terraced */
    public const float MAX_SLOPE_PERCENT = 5.0;
    /** with a point given, only zones within this distance of it */
    public const float NEAR_RADIUS_METRES = 30.0;
    /** a zone as a whole is offered when it is at most this much larger than an asked-for area */
    private const float WHOLE_ZONE_OVER_ASKED = 1.5;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const string ID_PREFIX = 'permeable-';

    private const string SHAPE_ZONE = 'zone';
    private const string SHAPE_BAND = 'band';
    private const string SHAPE_TREES = 'trees';
    /** a zone's options, the gentlest first */
    private const array SHAPE_ORDER = [self::SHAPE_BAND => 0, self::SHAPE_TREES => 1, self::SHAPE_ZONE => 2];

    public function __construct(
        private SurfaceZones $zones = new SurfaceZones(),
    ) {
    }

    public function propose(PlacementContext $context, PlacementRequest $request): Proposal
    {
        $ground = $context->ground;
        $zones = $this->zones->of($context->objects, $ground);
        $plotCells = count(array_filter($ground->blocked, static fn (?string $b): bool => OpenGround::OUTSIDE !== $b));
        $options = [];
        $kept = [];
        foreach ($zones as $zone) {
            if (null !== $request->near && !$this->zones->isNear($ground, $zone, $request->near, self::NEAR_RADIUS_METRES)) {
                continue;
            }
            $reason = $this->keptBecause($zone);
            if (null !== $reason) {
                $kept[$reason] = ($kept[$reason] ?? 0.0) + $zone->areaM2;
                continue;
            }
            // what is planned there already is unsealed already; play equipment keeps its fall zone
            $cells = array_filter($zone->cells, static fn (bool $_, int $cell): bool => !in_array($ground->blocked[$cell], [OpenGround::PLANNED, OpenGround::EQUIPMENT], true), \ARRAY_FILTER_USE_BOTH);
            foreach ($this->shapes($ground, $zone, $cells, $request) as [$shape, $shapeCells]) {
                foreach ($this->zones->pieces($ground, $shapeCells) as $piece) {
                    $option = $this->option($context, $zone, $shape, $piece);
                    if (null !== $option) {
                        $options[] = $option;
                    }
                }
            }
        }
        // the zones whose best option takes the most runoff off, each with its options side by side:
        // alternatives for the same ground (along the façade, under the trees, all of it), one to choose
        $byZone = [];
        foreach ($options as $option) {
            $byZone[$option['zone']][] = $option;
        }
        uasort($byZone, static fn (array $a, array $b): int => max(array_column($b, 'removed')) <=> max(array_column($a, 'removed')));
        $candidates = [];
        foreach (array_slice($byZone, 0, $request->count, true) as $zoneOptions) {
            usort($zoneOptions, static fn (array $a, array $b): int => self::SHAPE_ORDER[$a['shape']] <=> self::SHAPE_ORDER[$b['shape']]);
            $ids = array_map(static fn (int $i): string => self::ID_PREFIX.(count($candidates) + $i + 1), array_keys($zoneOptions));
            foreach ($zoneOptions as $i => $option) {
                $candidates[] = $option['candidate']->withId($ids[$i])->withMetric(Placements::ALTERNATIVES, array_values(array_diff($ids, [$ids[$i]])));
            }
        }

        $excluded = [];
        foreach ($kept as $reason => $area) {
            $excluded[$reason] = $plotCells > 0 ? round($area / ($plotCells * OpenGround::CELL_METRES ** 2), 3) : 0.0;
        }
        $note = match (true) {
            [] !== $candidates => null,
            [] === $zones => 'No open ground on this plot between its buildings and paths.',
            default => 'No zone here is known to be sealed and free to change (see excluded_share_of_plot for what is kept and why). Ask the user which ground is asphalt or paving and what it is used for, record it with describe_zone, and propose again. Do not draw paving yourself.',
        };

        return new Proposal(O::PERMEABLE, $candidates, $excluded, $this->assumptions(), $note);
    }

    public function stillFits(PlacementContext $context, Candidate $candidate): ?string
    {
        $ground = $context->ground;
        $outline = array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], $candidate->object[O::POINTS]);
        [$minX, $maxX, $minZ, $maxZ] = Polygon::bounds($outline);
        for ($x = $minX + 0.5; $x < $maxX; $x += OpenGround::CELL_METRES) {
            for ($z = $minZ + 0.5; $z < $maxZ; $z += OpenGround::CELL_METRES) {
                $cell = $ground->cellAt($x, $z);
                if (null !== $cell && Polygon::contains($outline, $x, $z) && in_array($ground->blocked[$cell], [OpenGround::BUILDING, OpenGround::PLANNED, OpenGround::EQUIPMENT], true)) {
                    return 'The plan changed since this candidate was proposed: something is planned or stands where it would go. Propose again.';
                }
            }
        }

        return null;
    }

    private function keptBecause(Zone $zone): ?string
    {
        if (isset(self::KEPT[$zone->use])) {
            return self::KEPT[$zone->use];
        }
        if (O::SURFACE_UNKNOWN === $zone->surface) {
            return self::UNKNOWN_GROUND;
        }

        return GroundClass::fromSurface($zone->surface)->isSealed() ? null : self::KEPT[O::AREA_GREEN];
    }

    /**
     * The shapes a zone offers, as cell sets: along a façade, under its trees, as a whole.
     *
     * @param array<int, true> $cells the zone's cells not yet planned
     *
     * @return list<array{0: string, 1: array<int, true>}>
     */
    private function shapes(OpenGround $ground, Zone $zone, array $cells, PlacementRequest $request): array
    {
        $shapes = [];
        $asked = $request->areaM2;
        $cellArea = OpenGround::CELL_METRES ** 2;
        // a band along the walls: as deep as needed for an asked area, else the standard depth
        $band = [];
        for ($depth = self::BAND_METRES; $depth <= self::MAX_BAND_METRES; $depth += self::BAND_STEP_METRES) {
            $band = array_filter($cells, static fn (bool $_, int $cell): bool => $ground->buildingDistance[$cell] < SurfaceZones::WALL_STRIP_METRES + $depth, \ARRAY_FILTER_USE_BOTH);
            if (null === $asked || count($band) * $cellArea >= $asked) {
                break;
            }
        }
        if ([] !== $band && count($band) < count($cells)) {
            $shapes[] = [self::SHAPE_BAND, $band];
        }
        $trees = array_filter($cells, static fn (bool $_, int $cell): bool => OpenGround::ROOT_ZONE === $ground->blocked[$cell], \ARRAY_FILTER_USE_BOTH);
        if ([] !== $trees && count($trees) < count($cells)) {
            $shapes[] = [self::SHAPE_TREES, $trees];
        }
        if (null === $asked || count($cells) * $cellArea <= $asked * self::WHOLE_ZONE_OVER_ASKED) {
            $shapes[] = [self::SHAPE_ZONE, $cells];
        }

        return $shapes;
    }

    /**
     * @param array<int, true> $cells one connected piece of a shape
     *
     * @return array{zone: string, shape: string, removed: float, candidate: Candidate}|null
     */
    private function option(PlacementContext $context, Zone $zone, string $shape, array $cells): ?array
    {
        $ground = $context->ground;
        $outline = CellOutline::of($ground, $cells, rounded: false);
        if ([] === $outline) {
            return null;
        }
        $area = Polygon::area($outline);
        if ($area < self::MIN_AREA_M2) {
            return null;
        }
        // under trees a water-bound surface: no deep excavation in the root zone
        $kind = self::SHAPE_TREES === $shape ? O::KIND_GRAVEL : self::MATERIALS[$zone->use];
        $today = GroundClass::fromSurface($zone->surface);
        $after = GroundClass::fromPermeableKind($kind);
        $rain = $context->stormMillimetres / self::MILLIMETRES_PER_METRE;
        $removed = max(0.0, $area * ($today->runoffCoefficient() - $after->runoffCoefficient()) * $rain);
        $slope = $this->slopePercent($context, $cells);
        $first = (int) array_key_first($cells);
        $potential = $context->infiltrationAt($first);
        $feedsGarden = $this->feedsRainGarden($context, $outline);
        $points = array_map(static fn (array $p): array => [(int) round($p[0] * self::MILLIMETRES_PER_METRE), (int) round($p[1] * self::MILLIMETRES_PER_METRE)], $outline);

        $reasons = [sprintf('%s (%s): %s.', $zone->label, Zone::ASSUMED === $zone->source ? 'open ground between buildings and paths, taken as a yard' : (Zone::FROM_USER === $zone->source ? 'its use as the user set it' : 'from OpenStreetMap'), self::SHAPE_TREES === $shape ? 'a water-bound surface under the trees gives their roots air and water' : self::WHY[$zone->use])];
        $reasons[] = match ($shape) {
            self::SHAPE_BAND => sprintf('A band along the building, %d m deep: the rest of the zone stays as it is (open for play in a yard).', (int) self::BAND_METRES),
            self::SHAPE_TREES => 'The ground under the trees\' crowns, plus 1.5 m (their root zone, DIN 18920).',
            default => 'The whole zone, along its edges: buildings, paths and the neighbouring zones.',
        };
        $reasons[] = sprintf('Takes about %.0f m³ off the runoff in a %d mm/h storm (%s, runoff coefficient %.2f, becomes %s, %.2f).', $removed, $context->stormMillimetres, $zone->surface, $today->runoffCoefficient(), $kind, $after->runoffCoefficient());
        $reasons[] = $this->materialTradeOff($zone, $shape, $kind, $area, $rain);
        if ($feedsGarden) {
            $reasons[] = 'Its water runs into a planned rain garden: unsealing here leaves the garden room for the rest.';
        }

        $warnings = [];
        if ($slope > self::MAX_SLOPE_PERCENT) {
            $warnings[] = sprintf('The ground slopes %.0f %% here: above %.0f %% the base needs terraces or check dams.', $slope, self::MAX_SLOPE_PERCENT);
        }
        if (InfiltrationPotential::Limited === $potential || InfiltrationPotential::Unlikely === $potential) {
            $warnings[] = sprintf('Infiltration is %s here (Versickerungspotentialkarte Hamburg): build it with a drained base that leads the water on, it will not soak away.', $potential->value);
        }
        if (self::SHAPE_TREES === $shape) {
            $warnings[] = 'In the root zone: no deep excavation, lay the surface by hand or with an air spade (DIN 18920).';
        }
        if (Zone::ASSUMED === $zone->source) {
            $warnings[] = 'Its use is assumed (a yard): ask the user whether it is a fire lane, a pitch or a play area, and record it with describe_zone.';
        }

        return [
            'zone' => $zone->id,
            'shape' => $shape,
            'removed' => $removed,
            'candidate' => new Candidate(
                '',
                [O::TYPE => O::PERMEABLE, O::KIND => $kind, O::POINTS => $points, O::LABEL => SiteType::shortLabel(sprintf('%s: %s', ucfirst(str_replace('_', ' ', $kind)), $zone->label))],
                [
                    'area_m2' => round($area, 1),
                    'kind' => $kind,
                    'kinds_not_chosen' => $this->rejectedKinds($zone, $shape, $kind),
                    'zone' => $zone->id,
                    'zone_use' => $zone->use,
                    'zone_use_source' => $zone->source,
                    'shape' => $shape,
                    'share_of_zone' => round(min(1.0, $area / max(1.0, $zone->areaM2)), 2),
                    'surface_today' => $zone->surface,
                    'runoff_removed_m3' => round($removed, 1),
                    'slope_percent' => round($slope, 1),
                    'infiltration' => $potential->value,
                    'feeds_rain_garden' => $feedsGarden,
                ],
                $reasons,
                $warnings,
            ),
        ];
    }

    /**
     * Why the other permeable kinds were not chosen here: kind → reason.
     *
     * @return array<string, string>
     */
    private function rejectedKinds(Zone $zone, string $shape, string $kind): array
    {
        $rejected = [];
        foreach (array_diff(O::PERMEABLE_KINDS, [$kind]) as $other) {
            $rejected[$other] = self::SHAPE_TREES === $shape ? self::WHY_NOT_IN_ROOT_ZONE : (self::WHY_NOT[$zone->use][$other] ?? '');
        }

        return $rejected;
    }

    /**
     * The material against the one that would take the most rain: what the choice costs or gains
     * in runoff and surface heat, so the trade-off can be explained, not only the result.
     */
    private function materialTradeOff(Zone $zone, string $shape, string $kind, float $area, float $rain): string
    {
        $chosen = GroundClass::fromPermeableKind($kind);
        $rejected = $this->rejectedKinds($zone, $shape, $kind);
        $best = array_key_first($rejected);
        foreach (array_keys($rejected) as $other) {
            if (GroundClass::fromPermeableKind($other)->runoffCoefficient() < GroundClass::fromPermeableKind($best)->runoffCoefficient()) {
                $best = $other;
            }
        }
        $rival = GroundClass::fromPermeableKind($best);
        $runoff = $area * ($rival->runoffCoefficient() - $chosen->runoffCoefficient()) * $rain;
        $heat = $rival->surfaceTemperature() - $chosen->surfaceTemperature();

        return sprintf(
            '%s rather than %s: %s. %s would take about %.1f m³ %s off the runoff and be %.0f K %s at midday.',
            ucfirst(str_replace('_', ' ', $kind)),
            str_replace('_', ' ', $best),
            $rejected[$best],
            ucfirst(str_replace('_', ' ', $best)),
            abs($runoff),
            $runoff <= 0 ? 'more' : 'less',
            abs($heat),
            $heat <= 0 ? 'cooler' : 'warmer',
        );
    }

    /**
     * The mean slope of the ground under the cells, from the terrain (0 without one).
     *
     * @param array<int, true> $cells
     */
    private function slopePercent(PlacementContext $context, array $cells): float
    {
        $terrain = $context->terrain;
        if (null === $terrain || [] === $cells) {
            return 0.0;
        }
        $sum = 0.0;
        foreach (array_keys($cells) as $cell) {
            [$x, $z] = $context->ground->centre($cell);
            $sum += hypot($terrain->heightAt($x + 1, $z) - $terrain->heightAt($x - 1, $z), $terrain->heightAt($x, $z + 1) - $terrain->heightAt($x, $z - 1)) / 2;
        }

        return $sum / count($cells) * 100;
    }

    /**
     * @param list<array{0: float, 1: float}> $outline plan metres
     */
    private function feedsRainGarden(PlacementContext $context, array $outline): bool
    {
        $runoff = $context->runoff;
        if (null === $runoff) {
            return false;
        }
        foreach ($runoff->nodesIn($outline) as $node) {
            if ($runoff->isCaught($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function assumptions(): array
    {
        return [
            'The plot is read as zones: areas from OpenStreetMap or set by the user, and the open ground between buildings and paths (taken as a yard until its use is set).',
            'The use decides the material: yards permeable pavers, car parks grass pavers, quiet areas a water-bound gravel surface; access lanes, sports and play areas keep their surfaces.',
            sprintf('Shapes follow the site\'s lines: a whole zone, a band along the façade (%d m), or the trees\' root zones (gravel, no deep excavation). A drip strip of %.0f m along the walls stays.', (int) self::BAND_METRES, SurfaceZones::WALL_STRIP_METRES),
            'Runoff removed: area times the drop of the runoff coefficient (DWA-A 138 / DIN 1986-100 classes) times the design storm.',
            sprintf('Above %.0f %% slope the base needs terraces; where infiltration is limited or unlikely the base is drained.', self::MAX_SLOPE_PERCENT),
        ];
    }
}

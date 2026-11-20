<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use Core\Configurator\Product\SpongeCity\Impact\GroundClass;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Product\SpongeCity\SpongeCityDefinition;
use Core\Configurator\Product\SpongeCity\SpongeCitySku as Sku;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Sealed ground that nobody needs to walk or drive on, broken up and planted: the most rain stays
 * and the ground is coolest there, for the least money. Where people do walk, permeable paving
 * (PermeableSiting) is the answer instead. Zone by zone, like paving: quiet areas as a whole, in a
 * yard a planted strip along the façades (the middle stays open for play), and the ground under the
 * trees, whose roots gain air and water. Car parks, access lanes, sports and play areas keep their
 * surfaces.
 */
#[AsTaggedItem(index: O::PLANTING)]
final readonly class PlantingSiting implements InterventionSiting
{
    /** use → the shapes it may be planted in */
    public const array SHAPES_BY_USE = [
        O::AREA_YARD => [self::SHAPE_BAND, self::SHAPE_TREES],
        O::AREA_QUIET => [self::SHAPE_BAND, self::SHAPE_TREES, self::SHAPE_ZONE],
    ];
    /** use → why it gets no planting (beyond PermeableSiting::KEPT) */
    public const array NOT_PLANTED = [
        O::AREA_PARKING => 'car parks need a surface that carries cars: grass pavers (type "permeable"), not planting',
    ];
    /** a planted strip along a façade, metres deep from the drip strip: room for shrubs, the yard stays open */
    public const float BAND_METRES = 3.0;
    /** a bed smaller than this is not worth breaking up the asphalt for */
    public const float MIN_AREA_M2 = 15.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const string ID_PREFIX = 'planting-';

    public const string SHAPE_ZONE = 'zone';
    public const string SHAPE_BAND = 'band';
    public const string SHAPE_TREES = 'trees';
    /** a zone's options, the gentlest first */
    private const array SHAPE_ORDER = [self::SHAPE_TREES => 0, self::SHAPE_BAND => 1, self::SHAPE_ZONE => 2];

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
            if (null !== $request->near && !$this->zones->isNear($ground, $zone, $request->near, PermeableSiting::NEAR_RADIUS_METRES)) {
                continue;
            }
            $reason = $this->keptBecause($zone);
            if (null !== $reason) {
                $kept[$reason] = ($kept[$reason] ?? 0.0) + $zone->areaM2;
                continue;
            }
            $cells = array_filter($zone->cells, static fn (bool $_, int $cell): bool => !in_array($ground->blocked[$cell], [OpenGround::PLANNED, OpenGround::EQUIPMENT], true), \ARRAY_FILTER_USE_BOTH);
            foreach ($this->shapes($ground, $zone, $cells) as [$shape, $shapeCells]) {
                foreach ($this->zones->pieces($ground, $shapeCells) as $piece) {
                    $option = $this->option($context, $zone, $shape, $piece);
                    if (null !== $option) {
                        $options[] = $option;
                    }
                }
            }
        }

        // per zone its options side by side, the zones that keep the most rain first; one to choose per zone
        $byZone = [];
        foreach ($options as $option) {
            $byZone[$option['zone']][] = $option;
        }
        uasort($byZone, static fn (array $a, array $b): int => max(array_column($b, 'removed')) <=> max(array_column($a, 'removed')));
        $candidates = [];
        foreach (array_slice($byZone, 0, $request->count, true) as $zoneOptions) {
            usort($zoneOptions, static fn (array $a, array $b): int => [self::SHAPE_ORDER[$a['shape']], -$a['removed']] <=> [self::SHAPE_ORDER[$b['shape']], -$b['removed']]);
            $zoneOptions = array_slice($zoneOptions, 0, PlacementRequest::MAX_COUNT);
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
            default => 'No sealed zone here that nobody needs to walk or drive on (see excluded_share_of_plot for what is kept and why). Ask the user which ground is asphalt or paving, which is a quiet edge and where the children play, record it with describe_zone, and propose again.',
        };

        return new Proposal(O::PLANTING, $candidates, $excluded, $this->assumptions(), $note);
    }

    public function stillFits(PlacementContext $context, Candidate $candidate): ?string
    {
        $ground = $context->ground;
        $outline = array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], $candidate->object[O::POINTS]);
        [$minX, $maxX, $minZ, $maxZ] = Polygon::bounds($outline);
        for ($x = $minX + OpenGround::CELL_METRES / 2; $x < $maxX; $x += OpenGround::CELL_METRES) {
            for ($z = $minZ + OpenGround::CELL_METRES / 2; $z < $maxZ; $z += OpenGround::CELL_METRES) {
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
        if (isset(PermeableSiting::KEPT[$zone->use])) {
            return PermeableSiting::KEPT[$zone->use];
        }
        if (isset(self::NOT_PLANTED[$zone->use])) {
            return self::NOT_PLANTED[$zone->use];
        }
        if (O::SURFACE_UNKNOWN === $zone->surface) {
            return PermeableSiting::UNKNOWN_GROUND;
        }

        return GroundClass::fromSurface($zone->surface)->isSealed() ? null : PermeableSiting::KEPT[O::AREA_GREEN];
    }

    /**
     * @param array<int, true> $cells the zone's cells not yet planned
     *
     * @return list<array{0: string, 1: array<int, true>}>
     */
    private function shapes(OpenGround $ground, Zone $zone, array $cells): array
    {
        $shapes = [];
        foreach (self::SHAPES_BY_USE[$zone->use] ?? [] as $shape) {
            $shapeCells = match ($shape) {
                self::SHAPE_BAND => array_filter($cells, static fn (bool $_, int $cell): bool => $ground->buildingDistance[$cell] < SurfaceZones::WALL_STRIP_METRES + self::BAND_METRES, \ARRAY_FILTER_USE_BOTH),
                self::SHAPE_TREES => array_filter($cells, static fn (bool $_, int $cell): bool => OpenGround::ROOT_ZONE === $ground->blocked[$cell], \ARRAY_FILTER_USE_BOTH),
                default => $cells,
            };
            // a part that is the whole zone is offered once, as the zone
            if ([] !== $shapeCells && (self::SHAPE_ZONE === $shape || count($shapeCells) < count($cells))) {
                $shapes[] = [$shape, $shapeCells];
            }
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
        $outline = CellOutline::of($context->ground, $cells, rounded: false);
        if ([] === $outline) {
            return null;
        }
        $area = Polygon::area($outline);
        if ($area < self::MIN_AREA_M2) {
            return null;
        }
        $today = GroundClass::fromSurface($zone->surface);
        $after = GroundClass::Planting;
        $rain = $context->stormMillimetres / self::MILLIMETRES_PER_METRE;
        $removed = max(0.0, $area * ($today->runoffCoefficient() - $after->runoffCoefficient()) * $rain);
        $cooler = $today->surfaceTemperature() - $after->surfaceTemperature();
        $pavers = GroundClass::PermeablePavers;
        $costEur = $area * (int) (new SpongeCityDefinition())->defaultPrices()->priceOf(Sku::PLANTING) / 100;
        $points = array_map(static fn (array $p): array => [(int) round($p[0] * self::MILLIMETRES_PER_METRE), (int) round($p[1] * self::MILLIMETRES_PER_METRE)], $outline);

        $reasons = [sprintf('%s (%s): nobody needs to walk or drive %s, so it can be broken up and planted.', $zone->label, Zone::ASSUMED === $zone->source ? 'open ground between buildings and paths, taken as a yard' : (Zone::FROM_USER === $zone->source ? 'its use as the user set it' : 'from OpenStreetMap'), match ($shape) {
            self::SHAPE_BAND => 'right along the wall',
            self::SHAPE_TREES => 'over the trees\' roots',
            default => 'on a quiet area',
        })];
        $reasons[] = match ($shape) {
            self::SHAPE_BAND => sprintf('A planted strip along the building, %.0f m deep: the rest of the zone stays open for play.', self::BAND_METRES),
            self::SHAPE_TREES => 'The ground under the trees\' crowns, plus 1.5 m (their root zone, DIN 18920): unsealed, the roots get air and water.',
            default => 'The whole zone, along its edges.',
        };
        $reasons[] = sprintf('Takes about %.0f m³ off the runoff in a %d mm/h storm (%s, runoff coefficient %.2f, becomes planting, %.2f) and is about %.0f K cooler at midday.', $removed, $context->stormMillimetres, $zone->surface, $today->runoffCoefficient(), $after->runoffCoefficient(), $cooler);
        $reasons[] = sprintf('Planting rather than permeable pavers: where nobody walks it keeps %.1f m³ more rain, is %.0f K cooler and costs less.', $area * ($pavers->runoffCoefficient() - $after->runoffCoefficient()) * $rain, $pavers->surfaceTemperature() - $after->surfaceTemperature());

        $warnings = ['The price covers soil and plants: breaking up and disposing of the asphalt (and testing it for tar) comes on top.'];
        if (self::SHAPE_TREES === $shape) {
            $warnings[] = 'In the root zone: break up the asphalt by hand or with an air spade, no deep excavation (DIN 18920).';
        }
        if (Zone::ASSUMED === $zone->source) {
            $warnings[] = 'Its use is assumed (a yard): ask the user whether people cross it, whether it is a fire lane or a play area, and record it with describe_zone.';
        }

        return [
            'zone' => $zone->id,
            'shape' => $shape,
            'removed' => $removed,
            'candidate' => new Candidate(
                '',
                [O::TYPE => O::PLANTING, O::POINTS => $points, O::LABEL => SiteType::shortLabel(sprintf('Planting: %s', $zone->label))],
                [
                    'area_m2' => round($area, 1),
                    'zone' => $zone->id,
                    'zone_use' => $zone->use,
                    'zone_use_source' => $zone->source,
                    'shape' => $shape,
                    'share_of_zone' => round(min(1.0, $area / max(1.0, $zone->areaM2)), 2),
                    'surface_today' => $zone->surface,
                    'runoff_removed_m3' => round($removed, 1),
                    'cooler_k' => round($cooler, 1),
                    'cost_eur' => round($costEur),
                ],
                $reasons,
                $warnings,
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function assumptions(): array
    {
        return [
            'Planting goes where nobody needs to walk or drive: quiet areas, a strip along the façades of a yard, the root zones of trees. Car parks, access lanes, sports and play areas keep their surfaces; ground nobody has described is never touched.',
            sprintf('Shapes follow the site\'s lines: a strip of %.0f m along the façade (after a drip strip of %.0f m), the trees\' root zones, or a quiet zone as a whole.', self::BAND_METRES, SurfaceZones::WALL_STRIP_METRES),
            'Runoff removed: area times the drop of the runoff coefficient (DWA-A 138 / DIN 1986-100 classes) times the design storm; cooler: indicative midday surface temperatures.',
            'Cost: the price list\'s planting per m²; breaking up and disposing of the sealed surface is not included.',
        ];
    }
}

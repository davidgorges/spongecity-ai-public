<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use App\SpongeCity\Placement\Zone;
use Core\Configurator\Product\SpongeCity\GreenRoof\GreenRoofs;
use Core\Configurator\Product\SpongeCity\GreenRoof\RoofLevel;
use Core\Configurator\Product\SpongeCity\InterventionBuilder;
use Core\Configurator\Site\SiteBuilder;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a person can point at in the 3D view, in plan millimetres: the planned interventions,
 * play equipment, trees, buildings and the plot's zones, each with its outline (or position and
 * crown), what may be done with it and its facts, ready to read.
 */
final readonly class PlanObjects
{
    /** what the menu offers for an object */
    public const string CAN_EDIT = 'edit';
    public const string CAN_DELETE = 'delete';
    /** a building whose roof can be planted and has no green roof yet */
    public const string CAN_GREENROOF = 'greenroof';
    /** how an object is found under the pointer: its outline on the ground, or standing above it */
    public const string ON_GROUND = 'ground';
    public const string UPRIGHT = 'upright';
    /** a zone is no site object of its own: its type in the list */
    public const string ZONE = 'zone';

    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const float SQUARE_MILLIMETRES_PER_SQUARE_METRE = 1_000_000.0;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param list<Zone> $zones
     *
     * Shapes: an outline (points), or a tree's position and crown (x, z, r); what stands above the
     * ground also its base and top over it (a building's roof, a crown, a sail), millimetres
     *
     * @return list<array{id: string, type: string, name: string, stands: string, points?: list<array{0: float, 1: float}>, x?: float, z?: float, r?: float, base?: float, top?: float, can: list<string>, facts: list<array{0: string, 1: string, 2: string|null}>}>
     */
    public function of(Site $site, array $zones): array
    {
        $objects = [];
        foreach ($site->objects as $object) {
            $type = (string) $object[O::TYPE];
            $row = match (true) {
                O::GREENROOF === $type => $this->greenRoof($site, $object),
                in_array($type, O::INTERVENTIONS, true) => $this->intervention($object, $type),
                O::EQUIPMENT === $type => $this->row($object, $type, ['points' => $this->rounded(Site::outlinesOf($object)[0])], self::ON_GROUND, [self::CAN_EDIT, self::CAN_DELETE], [
                    $this->fact('kind', $this->translator->trans('plan.equipment.kind.'.$object[O::KIND])),
                    $this->fact('fall_zone', sprintf('%s × %s m', $this->metres($object[O::WIDTH]), $this->metres($object[O::DEPTH]))),
                    $this->fact('source', $this->translator->trans('plan.equipment.source.'.($object[O::LOOK] ?? O::LOOK_DATA))),
                ]),
                O::TREE === $type => $this->row($object, $type, $this->crown($object), self::UPRIGHT, [], [
                    $this->fact('height', $this->metres($object[O::HEIGHT]).' m'),
                    $this->fact('crown', $this->metres(2 * $this->crownRadius($object)).' m'),
                ]),
                O::BUILDING === $type && count(Site::points($object)) >= 3 => $this->building($site, $object),
                default => null,
            };
            if (null !== $row) {
                $objects[] = $row;
            }
        }
        foreach ($zones as $zone) {
            $objects[] = [
                'id' => $zone->id,
                'type' => self::ZONE,
                'name' => $zone->label,
                'stands' => self::ON_GROUND,
                'points' => array_map(static fn (array $p): array => [(float) $p[0], (float) $p[1]], $zone->points()),
                'can' => [self::CAN_EDIT],
                'facts' => [
                    $this->fact('use', $this->translator->trans('plan.zones.use.'.$zone->use)),
                    $this->fact('surface', $this->translator->trans('plan.zones.surface.'.$zone->surface)),
                    $this->fact('area', $this->squareMetres($zone->areaM2 * self::SQUARE_MILLIMETRES_PER_SQUARE_METRE)),
                    $this->fact('source', $this->translator->trans('plan.zones.source.'.$zone->source)),
                ],
            ];
        }

        return $objects;
    }

    /**
     * A building, and whether its roof can be planted: on the plot's own, the reason it cannot, or
     * that it can (the menu then offers a green roof).
     *
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    private function building(Site $site, array $object): array
    {
        $plot = $site->plot();
        $assessment = GreenRoofs::assess($object, null === $plot ? [] : Site::points($plot));
        $planted = null !== GreenRoofs::onBuilding($site, (string) $object[O::ID]);
        $can = $assessment->isEligible() && !$planted ? [self::CAN_GREENROOF] : [];
        $greenRoof = match (true) {
            !$assessment->onPlot => [],
            $planted => [$this->fact('green_roof', $this->translator->trans('plan.object.green_roof.planned'))],
            $assessment->isEligible() => [$this->fact('green_roof', $this->translator->trans('plan.object.green_roof.possible', [
                '%area%' => $this->squareMetres($assessment->plantedSquareMetres() * self::SQUARE_MILLIMETRES_PER_SQUARE_METRE),
                '%kinds%' => implode(' / ', array_map(fn (string $kind): string => $this->translator->trans('plan.object.kind.'.$kind), $assessment->kinds)),
            ]))],
            default => [$this->fact('green_roof', $assessment->reasons[0] ?? '')],
        };

        return $this->row($object, O::BUILDING, ['points' => Site::points($object), 'base' => 0.0, 'top' => (float) $object[O::HEIGHT]], self::UPRIGHT, $can, [
            $this->fact('height', $this->metres($object[O::HEIGHT]).' m'),
            ...((int) ($object[O::STOREYS] ?? 0) > 0 ? [$this->fact('storeys', (string) $object[O::STOREYS])] : []),
            $this->fact('footprint', $this->squareMetres(Polygon::area(Site::points($object)))),
            ...$greenRoof,
        ]);
    }

    /**
     * A green roof: found on its building's roof, with its kind (a person may change it), roof and planted area.
     *
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    private function greenRoof(Site $site, array $object): array
    {
        $levels = GreenRoofs::levelsOf($site, $object);
        // from the lowest roof's top to the highest planted build-up
        $roofTop = static fn (RoofLevel $level): float => $level->height + SiteBuilder::FOOTPRINT_ROOF;
        $base = [] === $levels ? 0.0 : min(array_map($roofTop, $levels));
        $top = [] === $levels ? 0.0 : max(array_map($roofTop, $levels)) + InterventionBuilder::greenRoofThickness((string) $object[O::KIND]);

        return $this->row($object, O::GREENROOF, ['points' => Site::points($object), 'base' => $base, 'top' => $top], self::UPRIGHT, [self::CAN_EDIT, self::CAN_DELETE], [
            $this->fact('kind', $this->translator->trans('plan.object.kind.'.$object[O::KIND]), O::KIND),
            $this->fact('roof_area', $this->squareMetres(array_sum(array_map(static fn (RoofLevel $l): float => $l->grossSquareMetres, $levels)) * self::SQUARE_MILLIMETRES_PER_SQUARE_METRE)),
            $this->fact('planted', $this->squareMetres(array_sum(array_map(static fn (RoofLevel $l): float => $l->plantedSquareMetres, $levels)) * self::SQUARE_MILLIMETRES_PER_SQUARE_METRE)),
        ]);
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    private function intervention(array $object, string $type): array
    {
        $area = O::NEWTREE === $type ? 0.0 : Polygon::area(Site::points($object));
        $facts = match ($type) {
            O::RAINGARDEN => [
                $this->fact('area', $this->squareMetres($area)),
                $this->fact('ponding', sprintf('%d mm', (int) $object[O::STORAGE]), O::STORAGE),
                $this->fact('storage', sprintf('%s m³', $this->number($area / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE * ((int) $object[O::STORAGE] / self::MILLIMETRES_PER_METRE + ImpactCalculator::RAIN_GARDEN_MEDIA_STORAGE)))),
            ],
            O::PERMEABLE => [$this->fact('kind', $this->translator->trans('plan.object.kind.'.$object[O::KIND]), O::KIND), $this->fact('area', $this->squareMetres($area))],
            O::NEWTREE => [$this->fact('height', $this->metres($object[O::HEIGHT]).' m', O::HEIGHT), $this->fact('crown', $this->metres(2 * $this->crownRadius($object)).' m')],
            O::SHADESAIL => [$this->fact('area', $this->squareMetres($area)), $this->fact('height_above', $this->metres($object[O::Y]).' m', O::Y)],
            default => [$this->fact('area', $this->squareMetres($area))],
        };
        $shape = match ($type) {
            O::NEWTREE => $this->crown($object),
            // a sail hangs at its height
            O::SHADESAIL => ['points' => Site::points($object), 'base' => (float) $object[O::Y], 'top' => (float) $object[O::Y]],
            default => ['points' => Site::points($object)],
        };

        return $this->row($object, $type, $shape, in_array($type, [O::NEWTREE, O::SHADESAIL], true) ? self::UPRIGHT : self::ON_GROUND, [self::CAN_EDIT, self::CAN_DELETE], $facts);
    }

    /**
     * @param array<string, mixed>                              $object
     * @param array<string, mixed>                              $shape
     * @param list<string>                                      $can
     * @param list<array{0: string, 1: string, 2: string|null}> $facts
     *
     * @return array<string, mixed>
     */
    private function row(array $object, string $type, array $shape, string $stands, array $can, array $facts): array
    {
        $kind = $this->translator->trans('plan.object.type.'.$type);
        $label = Site::label($object);

        return [
            'id' => (string) $object[O::ID],
            'type' => $type,
            'name' => null === $label || $label === $kind ? $kind : sprintf('%s · %s', $kind, $label),
            'stands' => $stands,
            ...$shape,
            'can' => $can,
            'facts' => $facts,
        ];
    }

    /**
     * A tree: where it stands, its crown's radius and from what height to what height the crown reaches.
     *
     * @param array<string, mixed> $object
     *
     * @return array{x: float, z: float, r: float, base: float, top: float}
     */
    private function crown(array $object): array
    {
        $height = (float) $object[O::HEIGHT];

        return ['x' => (float) $object[O::X], 'z' => (float) $object[O::Z], 'r' => round($this->crownRadius($object)), 'base' => round($height * ImpactCalculator::CROWN_BASE_SHARE), 'top' => $height];
    }

    /**
     * @param array<string, mixed> $object
     */
    private function crownRadius(array $object): float
    {
        $diameter = is_numeric($object[O::DIAMETER] ?? null) ? (float) $object[O::DIAMETER] : 0.0;

        return $diameter > 0 ? $diameter / 2 : (float) ($object[O::HEIGHT] ?? 0) * ImpactCalculator::CANOPY_RADIUS_SHARE;
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     *
     * @return list<array{0: float, 1: float}>
     */
    private function rounded(array $points): array
    {
        return array_map(static fn (array $p): array => [round($p[0]), round($p[1])], $points);
    }

    /**
     * @return array{0: string, 1: string}
     */
    /**
     * @param string|null $field the object's field it shows, where a person may change it
     *
     * @return array{0: string, 1: string, 2: string|null} its label, its value and that field
     */
    private function fact(string $key, string $value, ?string $field = null): array
    {
        return [$this->translator->trans('plan.object.fact.'.$key), $value, $field];
    }

    private function metres(mixed $millimetres): string
    {
        return $this->number((float) $millimetres / self::MILLIMETRES_PER_METRE);
    }

    private function squareMetres(float $squareMillimetres): string
    {
        return sprintf('%s m²', number_format($squareMillimetres / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE, 0, '.', ','));
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ','), '0'), '.');
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use App\SpongeCity\Placement\Zone;
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
    /** how an object is found under the pointer: its outline on the ground, or standing above it */
    public const string ON_GROUND = 'ground';
    public const string UPRIGHT = 'upright';
    /** a zone is no site object of its own: its type in the list */
    public const string ZONE = 'zone';

    private const string DOMAIN = 'spongecity';
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
     * ground also its base and top over it (a building's roof, a crown, a sail), millimetres.
     *
     * @return list<array{id: string, type: string, name: string, stands: string, points?: list<array{0: float, 1: float}>, x?: float, z?: float, r?: float, base?: float, top?: float, can: list<string>, facts: list<array{0: string, 1: string}>}>
     */
    public function of(Site $site, array $zones): array
    {
        $objects = [];
        foreach ($site->objects as $object) {
            $type = (string) $object[O::TYPE];
            $row = match (true) {
                in_array($type, O::INTERVENTIONS, true) => $this->intervention($object, $type),
                O::EQUIPMENT === $type => $this->row($object, $type, ['points' => $this->rounded(Site::outlinesOf($object)[0])], self::ON_GROUND, [self::CAN_EDIT, self::CAN_DELETE], [
                    $this->fact('kind', $this->trans('plan.equipment.kind.'.$object[O::KIND])),
                    $this->fact('fall_zone', sprintf('%s × %s m', $this->metres($object[O::WIDTH]), $this->metres($object[O::DEPTH]))),
                    $this->fact('source', $this->trans('plan.equipment.source.'.($object[O::LOOK] ?? O::LOOK_DATA))),
                ]),
                O::TREE === $type => $this->row($object, $type, $this->crown($object), self::UPRIGHT, [], [
                    $this->fact('height', $this->metres($object[O::HEIGHT]).' m'),
                    $this->fact('crown', $this->metres(2 * $this->crownRadius($object)).' m'),
                ]),
                O::BUILDING === $type && count(Site::points($object)) >= 3 => $this->row($object, $type, ['points' => Site::points($object), 'base' => 0.0, 'top' => (float) $object[O::HEIGHT]], self::UPRIGHT, [], [
                    $this->fact('height', $this->metres($object[O::HEIGHT]).' m'),
                    ...((int) ($object[O::STOREYS] ?? 0) > 0 ? [$this->fact('storeys', (string) $object[O::STOREYS])] : []),
                    $this->fact('footprint', $this->squareMetres(Polygon::area(Site::points($object)))),
                ]),
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
                    $this->fact('use', $this->trans('plan.zones.use.'.$zone->use)),
                    $this->fact('surface', $this->trans('plan.zones.surface.'.$zone->surface)),
                    $this->fact('area', $this->squareMetres($zone->areaM2 * self::SQUARE_MILLIMETRES_PER_SQUARE_METRE)),
                    $this->fact('source', $this->trans('plan.zones.source.'.$zone->source)),
                ],
            ];
        }

        return $objects;
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
                $this->fact('ponding', sprintf('%d mm', (int) $object[O::STORAGE])),
                $this->fact('storage', sprintf('%s m³', $this->number($area / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE * ((int) $object[O::STORAGE] / self::MILLIMETRES_PER_METRE + ImpactCalculator::RAIN_GARDEN_MEDIA_STORAGE)))),
            ],
            O::PERMEABLE => [$this->fact('kind', $this->trans('plan.object.kind.'.$object[O::KIND])), $this->fact('area', $this->squareMetres($area))],
            O::NEWTREE => [$this->fact('height', $this->metres($object[O::HEIGHT]).' m'), $this->fact('crown', $this->metres(2 * $this->crownRadius($object)).' m')],
            O::SHADESAIL => [$this->fact('area', $this->squareMetres($area)), $this->fact('height_above', $this->metres($object[O::Y]).' m')],
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
     * @param array<string, mixed>            $object
     * @param array<string, mixed>            $shape
     * @param list<string>                    $can
     * @param list<array{0: string, 1: string}> $facts
     *
     * @return array<string, mixed>
     */
    private function row(array $object, string $type, array $shape, string $stands, array $can, array $facts): array
    {
        $kind = $this->trans('plan.object.type.'.$type);
        $label = is_string($object[O::LABEL] ?? null) ? $object[O::LABEL] : null;

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
    private function fact(string $key, string $value): array
    {
        return [$this->trans('plan.object.fact.'.$key), $value];
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], self::DOMAIN);
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

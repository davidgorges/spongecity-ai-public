<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\SiteEditor;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;

/**
 * "Add a … here" from a point a person picked in the 3D view: not drawn where they clicked, but
 * placed by the same rules the agent uses. A rain garden goes where the water near the point
 * gathers, paving becomes what the clicked zone's use allows, a tree stands only on free ground.
 * Where nothing fits, the answer says why.
 */
final readonly class AddHere
{
    /** what can be added from the 3D view */
    public const array TYPES = [O::RAINGARDEN, O::PERMEABLE, O::NEWTREE, O::EQUIPMENT];

    /** the result's outcome, a translation key under plan.add.* with its parameters */
    public const string ADDED = 'added';
    public const string NOTHING_FITS = 'nothing_fits';
    public const string BLOCKED = 'blocked';
    public const string ZONE_KEPT = 'zone_kept';
    public const string ZONE_UNKNOWN = 'zone_unknown';
    public const string OFF_PLOT = 'off_plot';

    /** a new tree keeps this far from a wall (its crown grows) */
    public const float TREE_WALL_METRES = 3.0;
    private const int TREE_HEIGHT = 9000;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    /** cells a new tree may stand on: open ground, a play area, or a zone planned to be paved (trees in paving) */
    private const array TREE_GROUND = [null, OpenGround::PLAY_AREA];

    public function __construct(
        private Placements $placements,
        private SiteEditor $editor,
    ) {
    }

    /**
     * @param int $x plan millimetres
     * @param int $z plan millimetres
     *
     * @return array{outcome: string, id?: string, params: array<string, string|int|float>}
     */
    public function add(Plan $plan, string $type, int $x, int $z): array
    {
        $objects = SiteType::rawObjects((new SiteType())->normalize($plan->siteJson()));
        $ground = OpenGround::of($objects);
        $cell = $ground->cellAt($x / self::MILLIMETRES_PER_METRE, $z / self::MILLIMETRES_PER_METRE);
        if (null === $cell || OpenGround::OUTSIDE === $ground->blocked[$cell]) {
            return ['outcome' => self::OFF_PLOT, 'params' => []];
        }

        return match ($type) {
            O::RAINGARDEN => $this->rainGarden($plan, $x, $z),
            O::PERMEABLE => $this->paving($plan, $x, $z),
            O::NEWTREE => $this->tree($plan, $ground, $cell, $x, $z),
            O::EQUIPMENT => $this->added($this->editor->add($plan->id, [O::equipment(O::EQUIPMENT_OTHER, $x, $z, look: O::LOOK_USER)])['ids'][0] ?? null, $type),
            default => ['outcome' => self::NOTHING_FITS, 'params' => ['%reason%' => $type]],
        };
    }

    /**
     * @return array{outcome: string, id?: string, params: array<string, string|int|float>}
     */
    private function rainGarden(Plan $plan, int $x, int $z): array
    {
        $proposal = $this->placements->propose($plan, new PlacementRequest(O::RAINGARDEN, near: [$x, $z], count: 1));
        $candidate = $proposal->candidates[0] ?? null;
        if (null === $candidate) {
            return ['outcome' => self::NOTHING_FITS, 'params' => ['%reason%' => $proposal->note ?? '']];
        }
        $placed = $this->placements->place($plan, $candidate->id);
        $points = $candidate->object[O::POINTS];
        $distance = Polygon::contains($points, $x, $z) ? 0 : (int) round(hypot(
            array_sum(array_column($points, 0)) / count($points) - $x,
            array_sum(array_column($points, 1)) / count($points) - $z,
        ) / self::MILLIMETRES_PER_METRE);

        return $this->added($placed['id'] ?? null, O::RAINGARDEN, [
            '%area%' => (int) round($candidate->metrics['area_m2']),
            '%catchment%' => (int) round($candidate->metrics['catchment_m2'], -1),
            '%distance%' => $distance,
        ]);
    }

    /**
     * @return array{outcome: string, id?: string, params: array<string, string|int|float>}
     */
    private function paving(Plan $plan, int $x, int $z): array
    {
        $zone = null;
        foreach ($this->placements->zones($plan) as $candidate) {
            if (Polygon::contains($candidate->points(), $x, $z)) {
                $zone = $candidate;
                break;
            }
        }
        if (null === $zone) {
            return ['outcome' => self::BLOCKED, 'params' => ['%reason%' => 'no open ground of a zone here (a wall strip, a path or a building)']];
        }
        $proposal = $this->placements->propose($plan, new PlacementRequest(O::PERMEABLE, near: [$x, $z], count: PlacementRequest::MAX_COUNT));
        // the clicked zone's gentlest option (they come gentlest first: along the façade, under the trees, all of it)
        foreach ($proposal->candidates as $candidate) {
            if ($zone->id === ($candidate->metrics['zone'] ?? null)) {
                $placed = $this->placements->place($plan, $candidate->id);

                return $this->added($placed['id'] ?? null, O::PERMEABLE, [
                    '%area%' => (int) round($candidate->metrics['area_m2']),
                    '%kind%' => (string) $candidate->metrics['kind'],
                    '%zone%' => $zone->label,
                ]);
            }
        }
        if (O::SURFACE_UNKNOWN === $zone->surface) {
            return ['outcome' => self::ZONE_UNKNOWN, 'id' => $zone->id, 'params' => ['%zone%' => $zone->label]];
        }

        return ['outcome' => self::ZONE_KEPT, 'id' => $zone->id, 'params' => ['%zone%' => $zone->label, '%use%' => $zone->use]];
    }

    /**
     * @return array{outcome: string, id?: string, params: array<string, string|int|float>}
     */
    private function tree(Plan $plan, OpenGround $ground, int $cell, int $x, int $z): array
    {
        $reason = $ground->blocked[$cell];
        if (!in_array($reason, self::TREE_GROUND, true)) {
            return ['outcome' => self::BLOCKED, 'params' => ['%reason%' => (string) $reason]];
        }
        if ($ground->buildingDistance[$cell] < self::TREE_WALL_METRES) {
            return ['outcome' => self::BLOCKED, 'params' => ['%reason%' => sprintf('closer than %.0f m to a building', self::TREE_WALL_METRES)]];
        }

        return $this->added($this->editor->add($plan->id, [[O::TYPE => O::NEWTREE, O::X => $x, O::Z => $z, O::HEIGHT => self::TREE_HEIGHT]])['ids'][0] ?? null, O::NEWTREE);
    }

    /**
     * @param array<string, string|int|float> $params
     *
     * @return array{outcome: string, id?: string, params: array<string, string|int|float>}
     */
    private function added(?string $id, string $type, array $params = []): array
    {
        if (null === $id) {
            return ['outcome' => self::BLOCKED, 'params' => ['%reason%' => 'it would stand on a building']];
        }

        return ['outcome' => self::ADDED.'.'.$type, 'id' => $id, 'params' => $params];
    }
}

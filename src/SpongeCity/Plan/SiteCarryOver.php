<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;

/**
 * What a person added to a plan's site, carried over a re-import: the planned interventions,
 * and the looks of buildings a person corrected, found again by where they stand.
 */
final readonly class SiteCarryOver
{
    /** a re-imported building whose outline's centre lies this close is the same building */
    private const int SAME_BUILDING_MM = 2000;

    public function __construct(
        private SiteEditor $editor,
    ) {
    }

    public function capture(string $planId): CarriedOver
    {
        $objects = $this->editor->site($planId)->objects;

        return new CarriedOver(
            // the interventions, the uses a person set on the plot's zones and the equipment they marked (look "user")
            array_values(array_filter($objects, static fn (array $o): bool => in_array($o[O::TYPE] ?? null, O::INTERVENTIONS, true) || (in_array($o[O::TYPE] ?? null, [O::AREA, O::EQUIPMENT], true) && O::LOOK_USER === ($o[O::LOOK] ?? null)))),
            array_values(array_filter($objects, static fn (array $o): bool => O::BUILDING === ($o[O::TYPE] ?? null) && O::LOOK_USER === ($o[O::LOOK] ?? null))),
        );
    }

    /**
     * @return array{looks: int, conflicts: list<array<string, mixed>>} how many corrected looks found their building
     *                                                                  again, and the interventions left out because
     *                                                                  the new data has a building where they were
     */
    public function restore(string $planId, CarriedOver $kept): array
    {
        $buildings = $this->editor->site($planId)->ofType(O::BUILDING);
        // a green roof finds its building again by where it stands (the new data gives new ids)
        $interventions = array_map(static function (array $o) use ($buildings): array {
            if (O::GREENROOF !== $o[O::TYPE]) {
                return $o;
            }
            $building = array_find($buildings, static fn (array $b): bool => self::sameBuilding($b, $o));

            return null === $building ? $o : [...$o, O::BUILDING_ID => $building[O::ID]];
        }, $kept->interventions);
        $conflicts = [] === $interventions ? [] : $this->editor->add($planId, $interventions)['conflicts'];
        $looks = 0;
        foreach ($buildings as $building) {
            foreach ($kept->looks as $look) {
                if (self::sameBuilding($building, $look)) {
                    $this->editor->update($planId, (string) $building[O::ID], [O::FACADE => $look[O::FACADE], O::COLOR => $look[O::COLOR]]);
                    ++$looks;
                    break;
                }
            }
        }

        return ['looks' => $looks, 'conflicts' => $conflicts];
    }

    /**
     * The same outline again (its centre within a couple of metres), or the old one's centre inside
     * the new outline. The centre alone is not enough: a U-shaped building's lies in its courtyard.
     *
     * @param array<string, mixed> $building
     * @param array<string, mixed> $before
     */
    private static function sameBuilding(array $building, array $before): bool
    {
        $old = self::centre(Site::points($before));
        $new = self::centre(Site::points($building));
        if (null === $old || null === $new) {
            return false;
        }

        return hypot($old[0] - $new[0], $old[1] - $new[1]) <= self::SAME_BUILDING_MM
            || Polygon::contains(Site::points($building), $old[0], $old[1]);
    }

    /**
     * @param list<array{0: int|float, 1: int|float}> $points
     *
     * @return array{0: float, 1: float}|null
     */
    private static function centre(array $points): ?array
    {
        return [] === $points ? null : [array_sum(array_column($points, 0)) / count($points), array_sum(array_column($points, 1)) / count($points)];
    }
}

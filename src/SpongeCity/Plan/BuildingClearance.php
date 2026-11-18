<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;

/**
 * Interventions are planned in the open, never where a building stands: a rain garden, permeable
 * paving or planting drawn into a building would be hidden by it, yet counted in the bill of
 * quantities and the impact calculation as if it were there.
 */
final readonly class BuildingClearance
{
    /** less than this under a building is a drawing touching a wall, not a misplaced object (0.5 m²) */
    public const int TOLERANCE_MM2 = 500_000;

    /**
     * The first building an intervention stands on, or null when it stands in the open.
     *
     * @param array<string, mixed>       $object    a normalised site object
     * @param list<array<string, mixed>> $buildings normalised building objects
     */
    public function conflict(array $object, array $buildings): ?BuildingConflict
    {
        if (!in_array($object[O::TYPE] ?? null, O::INTERVENTIONS, true)) {
            return null;
        }
        $outline = isset($object[O::POINTS]) ? Site::points($object) : [];
        foreach ($buildings as $building) {
            $footprint = Site::points($building);
            if (count($footprint) < 3) {
                continue;
            }
            $label = is_string($building[O::LABEL] ?? null) ? $building[O::LABEL] : null;
            if ([] === $outline) {
                // a tree: its trunk must stand outside
                if (Polygon::contains($footprint, (float) ($object[O::X] ?? 0), (float) ($object[O::Z] ?? 0))) {
                    return new BuildingConflict((string) $building[O::ID], $label, 0.0);
                }
                continue;
            }
            $overlap = Polygon::overlapArea($outline, $footprint);
            if ($overlap >= self::TOLERANCE_MM2) {
                return new BuildingConflict((string) $building[O::ID], $label, $overlap);
            }
        }

        return null;
    }
}

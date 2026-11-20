<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;

/**
 * Divides a plot's open ground into zones: first the areas on the map or set by the user (a car
 * park, a pitch, an access lane), then what lies between buildings and paths, one zone per
 * coherent piece, taken as a yard of unknown ground until someone says otherwise (the plot's own
 * surface is a default, not a fact: such ground may be a lawn as well as asphalt). Ground under trees and under
 * planned interventions belongs to its zone; a strip along every wall does not.
 */
final readonly class SurfaceZones
{
    /** paving keeps off the wall (a drip strip): cells nearer than this to a building are no zone's */
    public const float WALL_STRIP_METRES = 1.0;
    /** a piece of open ground smaller than this is no zone of its own */
    public const float MIN_ZONE_M2 = 15.0;
    private const string ID_PREFIX = 'zone-';
    /** x east, z south: the angle from a building's middle, 0 = east */
    private const array DIRECTIONS = ['east', 'south-east', 'south', 'south-west', 'west', 'north-west', 'north', 'north-east'];
    private const array USE_NAMES = [
        O::AREA_YARD => 'Yard', O::AREA_PARKING => 'Car park', O::AREA_ACCESS => 'Access lane', O::AREA_SPORT => 'Sports area',
        O::AREA_PLAY => 'Play area', O::AREA_QUIET => 'Quiet area', O::AREA_GREEN => 'Green',
    ];
    private const float MILLIMETRES_PER_METRE = 1000.0;
    /** cells a zone may take: open, under a tree, under something planned, play equipment's fall zones, play and sports areas */
    private const array ZONE_GROUND = [null, OpenGround::ROOT_ZONE, OpenGround::PLANNED, OpenGround::EQUIPMENT, OpenGround::PLAY_AREA];

    /**
     * @param list<array<string, mixed>> $objects the plan's normalised site objects
     *
     * @return list<Zone> largest first
     */
    public function of(array $objects, OpenGround $ground): array
    {
        $plots = array_values(array_filter($objects, static fn (array $o): bool => O::PLOT === $o[O::TYPE]));
        $plotSurface = [] !== $plots && is_string($plots[0][O::SURFACE] ?? null) ? $plots[0][O::SURFACE] : O::SURFACE_ASPHALT;
        $buildings = array_values(array_filter($objects, static fn (array $o): bool => O::BUILDING === $o[O::TYPE] && count(Site::points($o)) >= 3));
        $free = [];
        for ($cell = 0, $n = $ground->cellCount(); $cell < $n; ++$cell) {
            if (in_array($ground->blocked[$cell], self::ZONE_GROUND, true) && $ground->buildingDistance[$cell] >= self::WALL_STRIP_METRES) {
                $free[$cell] = true;
            }
        }

        $zones = [];
        // the map's areas first, then the user's: what a person set wins where they overlap
        $areas = array_values(array_filter($objects, static fn (array $o): bool => O::AREA === $o[O::TYPE] && count(Site::points($o)) >= 3));
        usort($areas, static fn (array $a, array $b): int => (O::LOOK_USER === ($a[O::LOOK] ?? null)) <=> (O::LOOK_USER === ($b[O::LOOK] ?? null)));
        $claimed = [];
        foreach (array_reverse($areas) as $area) {
            $outline = array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], Site::points($area));
            [$minX, $maxX, $minZ, $maxZ] = Polygon::bounds($outline);
            $cells = [];
            foreach (array_keys($free) as $cell) {
                [$x, $z] = $ground->centre($cell);
                if (!isset($claimed[$cell]) && $x >= $minX && $x <= $maxX && $z >= $minZ && $z <= $maxZ && Polygon::contains($outline, $x, $z)) {
                    $cells[$cell] = true;
                }
            }
            if (count($cells) * OpenGround::CELL_METRES ** 2 < self::MIN_ZONE_M2) {
                continue;
            }
            $claimed += $cells;
            $use = (string) $area[O::USE];
            $zones[] = $this->zone(
                (string) $area[O::ID],
                $use,
                (string) ($area[O::SURFACE] ?? $plotSurface),
                O::LOOK_USER === ($area[O::LOOK] ?? null) ? Zone::FROM_USER : Zone::FROM_MAP,
                Site::label($area),
                $cells,
                $ground,
                $buildings,
                (string) $area[O::ID],
            );
        }

        // the rest of the open ground, piece by piece
        foreach ($this->pieces($ground, array_diff_key($free, $claimed)) as $cells) {
            if (count($cells) * OpenGround::CELL_METRES ** 2 >= self::MIN_ZONE_M2) {
                // nobody said what this ground is: it may be a lawn or a garden as well as asphalt
                $zones[] = $this->zone(self::ID_PREFIX.min(array_keys($cells)), O::AREA_YARD, O::SURFACE_UNKNOWN, Zone::ASSUMED, null, $cells, $ground, $buildings);
            }
        }
        $zones = array_values(array_filter($zones, static fn (Zone $z): bool => count($z->outline) >= 3));
        usort($zones, static fn (Zone $a, Zone $b): int => $b->areaM2 <=> $a->areaM2);

        return $zones;
    }

    public static function useName(string $use): string
    {
        return self::USE_NAMES[$use] ?? ucfirst($use);
    }

    /**
     * @param array<int, true>           $cells
     * @param list<array<string, mixed>> $buildings
     */
    private function zone(string $id, string $use, string $surface, string $source, ?string $name, array $cells, OpenGround $ground, array $buildings, ?string $objectId = null): Zone
    {
        return new Zone(
            $id,
            $use,
            $surface,
            $source,
            $name ?? $this->label($use, $cells, $ground, $buildings),
            $cells,
            CellOutline::of($ground, $this->largest($ground, $cells), rounded: false),
            count($cells) * OpenGround::CELL_METRES ** 2,
            $objectId,
        );
    }

    /**
     * "Yard south of …": its use and where it lies from the nearest building.
     *
     * @param array<int, true>           $cells
     * @param list<array<string, mixed>> $buildings
     */
    private function label(string $use, array $cells, OpenGround $ground, array $buildings): string
    {
        $centres = array_map($ground->centre(...), array_keys($cells));
        $x = array_sum(array_column($centres, 0)) / count($centres);
        $z = array_sum(array_column($centres, 1)) / count($centres);
        $nearest = null;
        $distance = INF;
        foreach ($buildings as $building) {
            $points = Site::points($building);
            $bx = array_sum(array_column($points, 0)) / count($points) / self::MILLIMETRES_PER_METRE;
            $bz = array_sum(array_column($points, 1)) / count($points) / self::MILLIMETRES_PER_METRE;
            if (hypot($bx - $x, $bz - $z) < $distance) {
                [$nearest, $distance] = [[$bx, $bz, $building], hypot($bx - $x, $bz - $z)];
            }
        }
        if (null === $nearest) {
            return self::useName($use);
        }
        $angle = rad2deg(atan2($z - $nearest[1], $x - $nearest[0]));
        $direction = self::DIRECTIONS[((int) round(($angle < 0 ? $angle + 360 : $angle) / 45)) % 8];
        $building = $nearest[2];
        $name = is_string($building[O::LABEL] ?? null) ? sprintf('"%s"', $building[O::LABEL]) : 'the building';

        return sprintf('%s %s of %s (%s)', self::useName($use), $direction, $name, $building[O::ID]);
    }

    /**
     * Whether any of a zone's cells lies within $radius metres of a point given in plan millimetres.
     *
     * @param array{0: int|float, 1: int|float} $near
     */
    public function isNear(OpenGround $ground, Zone $zone, array $near, float $radius): bool
    {
        foreach (array_keys($zone->cells) as $cell) {
            [$x, $z] = $ground->centre($cell);
            if (hypot($x - $near[0] / self::MILLIMETRES_PER_METRE, $z - $near[1] / self::MILLIMETRES_PER_METRE) <= $radius) {
                return true;
            }
        }

        return false;
    }

    /**
     * The 4-connected pieces of a cell set.
     *
     * @param array<int, true> $cells
     *
     * @return list<array<int, true>>
     */
    public function pieces(OpenGround $ground, array $cells): array
    {
        $pieces = [];
        $seen = [];
        foreach (array_keys($cells) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $piece = [$start => true];
            $seen[$start] = true;
            $stack = [$start];
            while ([] !== $stack) {
                $cell = array_pop($stack);
                [$c, $r] = [$cell % $ground->columns, intdiv($cell, $ground->columns)];
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dc, $dr]) {
                    $next = ($r + $dr) * $ground->columns + $c + $dc;
                    if ($c + $dc >= 0 && $c + $dc < $ground->columns && isset($cells[$next]) && !isset($seen[$next])) {
                        $seen[$next] = true;
                        $piece[$next] = true;
                        $stack[] = $next;
                    }
                }
            }
            $pieces[] = $piece;
        }

        return $pieces;
    }

    /**
     * The largest piece of a set, without corner-only contacts: what one outline can show.
     *
     * @param array<int, true> $cells
     *
     * @return array<int, true>
     */
    private function largest(OpenGround $ground, array $cells): array
    {
        $columns = $ground->columns;
        // a corner-only contact would pinch the traced outline: drop the cell that makes it
        foreach (array_keys($cells) as $cell) {
            [$c, $r] = [$cell % $columns, intdiv($cell, $columns)];
            foreach ([-1, 1] as $dc) {
                $diagonal = ($r + 1) * $columns + $c + $dc;
                if ($c + $dc >= 0 && $c + $dc < $columns && isset($cells[$cell], $cells[$diagonal]) && !isset($cells[$r * $columns + $c + $dc]) && !isset($cells[($r + 1) * $columns + $c])) {
                    unset($cells[$diagonal]);
                }
            }
        }
        $pieces = $this->pieces($ground, $cells);
        usort($pieces, static fn (array $a, array $b): int => count($b) <=> count($a));

        return $pieces[0] ?? [];
    }
}

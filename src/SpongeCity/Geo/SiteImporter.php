<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;

/**
 * Turns OpenStreetMap elements around a point into the site of a plan: the
 * plot (the school yard, park or garden the point lies in, else a square
 * around it), the buildings with their heights and the existing trees.
 *
 * Buildings are classified from their tags by BuildingTags (use, façade, roof,
 * height), like in urban energy simulation.
 */
final readonly class SiteImporter
{
    public const string TAG_BUILDING = BuildingTags::TAG_BUILDING;
    public const string TAG_LEVELS = BuildingTags::TAG_LEVELS;
    public const string TAG_HEIGHT = BuildingTags::TAG_HEIGHT;
    public const string TAG_LEISURE = 'leisure';
    public const string TAG_AMENITY = 'amenity';
    public const string TAG_LANDUSE = 'landuse';
    public const string TAG_SURFACE = 'surface';
    public const string TAG_NATURAL = 'natural';
    public const string TAG_MATERIAL = BuildingTags::TAG_MATERIAL;
    public const string TAG_COLOUR = BuildingTags::TAG_COLOUR;
    public const string TAG_ROOF_SHAPE = BuildingTags::TAG_ROOF_SHAPE;
    public const string TAG_ROOF_COLOUR = BuildingTags::TAG_ROOF_COLOUR;
    public const string TAG_NAME = 'name';
    public const string TAG_HIGHWAY = 'highway';
    /** crown diameter in metres (OpenStreetMap, or the tree register) */
    public const string TAG_CROWN = 'diameter_crown';
    public const string TAG_SPECIES = 'species:de';
    public const string TAG_PLANTED = 'start_date';
    /** the use of a building in the cadastre (ALKIS), when it has no name */
    public const string TAG_FUNCTION = 'alkis:funktion';

    private const float DEFAULT_TREE_METRES = 9.0;
    private const int FALLBACK_PLOT_HALF_SIDE_MM = 20_000;
    private const int MAX_BUILDINGS = 80;
    private const int MAX_TREES = 30;
    private const int MAX_POINTS = 36;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const int MAX_ROADS = 40;
    /** highway=* → width in millimetres (carriageway or path) */
    public const array ROAD_WIDTHS = [
        'primary' => 14000, 'secondary' => 11000, 'tertiary' => 9000, 'unclassified' => 7000, 'residential' => 7000,
        'living_street' => 6000, 'service' => 4500, 'pedestrian' => 6000, 'footway' => 2500, 'path' => 2000, 'cycleway' => 2500,
    ];
    public const array FOOTWAYS = ['pedestrian', 'footway', 'path', 'cycleway'];
    private const array PLOT_PRIORITY = [self::TAG_LEISURE => 3, self::TAG_AMENITY => 2, self::TAG_LANDUSE => 1];


    public function __construct(
        private BuildingTags $tags = new BuildingTags(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $elements
     */
    public function import(GeoPoint $centre, array $elements): SiteImport
    {
        $projection = new LocalProjection($centre);
        $objects = [];
        $plot = null;
        $plotRank = 0;
        $plotName = null;
        $buildings = [];
        $trees = [];
        $roads = [];

        foreach ($elements as $element) {
            $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
            if ('node' === ($element['type'] ?? null)) {
                if (isset($element['lat'], $element['lon'])) {
                    [$x, $z] = $projection->toPlan((float) $element['lat'], (float) $element['lon']);
                    $trees[] = [O::TYPE => O::TREE, O::X => $x, O::Z => $z, O::HEIGHT => $this->height($tags, self::DEFAULT_TREE_METRES), O::DIAMETER => $this->crown($tags), O::LABEL => $this->treeLabel($tags)];
                }
                continue;
            }
            $points = $this->outline($element, $projection);
            $highway = $this->tag($tags, self::TAG_HIGHWAY);
            if (isset(self::ROAD_WIDTHS[$highway]) && !isset($tags[self::TAG_BUILDING])) {
                foreach (self::clipToReach($points) as $piece) {
                    $roads[] = [O::TYPE => O::ROAD, O::POINTS => $piece, O::WIDTH => self::ROAD_WIDTHS[$highway], O::KIND => in_array($highway, self::FOOTWAYS, true) ? O::ROAD_FOOTWAY : O::ROAD_CARRIAGEWAY];
                }
                continue;
            }
            if (count($points) < 3) {
                continue;
            }
            if (isset($tags[self::TAG_BUILDING])) {
                // the site clamps coordinates to its reach, which would squash a building that crosses it: cut it there instead
                $points = self::clipOutlineToReach($points);
                if (count($points) >= 3) {
                    $buildings[] = [O::TYPE => O::BUILDING, O::POINTS => $points, O::HEIGHT => $this->tags->height($tags), ...$this->tags->appearance($tags, $points), O::LABEL => $this->name($tags) ?? $this->text($tags, self::TAG_FUNCTION)];
                }
                continue;
            }
            $rank = $this->plotRank($tags);
            if ($rank > 0 && Polygon::contains($points, 0, 0) && $rank >= $plotRank) {
                // among equals the smaller area is the more specific one
                if ($rank > $plotRank || null === $plot || Polygon::area($points) < Polygon::area($plot)) {
                    $plot = $points;
                    $plotRank = $rank;
                    $plotName = $this->name($tags);
                }
            }
        }

        $usedFallback = null === $plot;
        $plot ??= [[-self::FALLBACK_PLOT_HALF_SIDE_MM, -self::FALLBACK_PLOT_HALF_SIDE_MM], [self::FALLBACK_PLOT_HALF_SIDE_MM, -self::FALLBACK_PLOT_HALF_SIDE_MM], [self::FALLBACK_PLOT_HALF_SIDE_MM, self::FALLBACK_PLOT_HALF_SIDE_MM], [-self::FALLBACK_PLOT_HALF_SIDE_MM, self::FALLBACK_PLOT_HALF_SIDE_MM]];

        $objects[] = [O::TYPE => O::PLOT, O::POINTS => $plot, O::SURFACE => O::SURFACE_ASPHALT, O::LABEL => $plotName ?? $centre->label];
        // buildings inside the plot would hide its ground in the balance: they stay, they are real
        // the nearest first, when there are more than the site takes
        $nearest = static fn (array $a, array $b): int => self::distance($a) <=> self::distance($b);
        usort($buildings, $nearest);
        usort($roads, $nearest);
        usort($trees, static fn (array $a, array $b): int => hypot($a[O::X], $a[O::Z]) <=> hypot($b[O::X], $b[O::Z]));
        array_push($objects, ...array_slice($buildings, 0, self::MAX_BUILDINGS), ...array_slice($trees, 0, self::MAX_TREES), ...array_slice($roads, 0, self::MAX_ROADS));

        return new SiteImport(SiteType::encode($objects), $usedFallback, count($buildings), count($trees), Polygon::area($plot) / 1_000_000);
    }

    /**
     * The part of a closed outline inside the site's reach (Sutherland-Hodgman,
     * one side of the square at a time).
     *
     * @param list<array{0: int, 1: int}> $points
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function clipOutlineToReach(array $points): array
    {
        // each side: the axis (0 = x, 1 = z) and the sign of the limit
        foreach ([[0, 1], [0, -1], [1, 1], [1, -1]] as [$axis, $sign]) {
            $inside = static fn (array $p): bool => $sign * $p[$axis] <= O::REACH;
            $clipped = [];
            for ($i = 0, $count = count($points); $i < $count; ++$i) {
                $current = $points[$i];
                $previous = $points[($i + $count - 1) % $count];
                if ($inside($current) !== $inside($previous)) {
                    $t = ($sign * O::REACH - $previous[$axis]) / ($current[$axis] - $previous[$axis]);
                    $clipped[] = [
                        (int) round($previous[0] + $t * ($current[0] - $previous[0])),
                        (int) round($previous[1] + $t * ($current[1] - $previous[1])),
                    ];
                }
                if ($inside($current)) {
                    $clipped[] = $current;
                }
            }
            $points = $clipped;
        }

        return $points;
    }

    /**
     * The parts of a line inside the site's reach (a square around the centre),
     * each cut where it leaves the square (Liang-Barsky per leg).
     *
     * @param list<array{0: int, 1: int}> $points
     *
     * @return list<list<array{0: int, 1: int}>>
     */
    private static function clipToReach(array $points): array
    {
        $pieces = [];
        $piece = [];
        for ($i = 1, $count = count($points); $i < $count; ++$i) {
            $leg = self::clipLeg($points[$i - 1], $points[$i]);
            if (null === $leg) {
                continue;
            }
            [$from, $to] = $leg;
            if ([] !== $piece && end($piece) !== $from) {
                $pieces[] = $piece;
                $piece = [];
            }
            if ([] === $piece) {
                $piece[] = $from;
            }
            $piece[] = $to;
        }
        if (count($piece) >= 2) {
            $pieces[] = $piece;
        }

        return array_values(array_filter($pieces, static fn (array $p): bool => count($p) >= 2));
    }

    /**
     * @param array{0: int, 1: int} $a
     * @param array{0: int, 1: int} $b
     *
     * @return array{array{0: int, 1: int}, array{0: int, 1: int}}|null
     */
    private static function clipLeg(array $a, array $b): ?array
    {
        [$dx, $dz] = [$b[0] - $a[0], $b[1] - $a[1]];
        [$enter, $leave] = [0.0, 1.0];
        foreach ([[-$dx, $a[0] + O::REACH], [$dx, O::REACH - $a[0]], [-$dz, $a[1] + O::REACH], [$dz, O::REACH - $a[1]]] as [$p, $q]) {
            if (0 === $p) {
                if ($q < 0) {
                    return null;
                }
                continue;
            }
            $t = $q / $p;
            if ($p < 0) {
                $enter = max($enter, $t);
            } else {
                $leave = min($leave, $t);
            }
        }
        if ($enter >= $leave) {
            return null;
        }
        $at = static fn (float $t): array => [(int) round($a[0] + $t * $dx), (int) round($a[1] + $t * $dz)];

        return [$at($enter), $at($leave)];
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function distance(array $object): float
    {
        $points = is_array($object[O::POINTS] ?? null) ? $object[O::POINTS] : [];
        $distances = array_map(static fn (array $p): float => hypot((float) $p[0], (float) $p[1]), $points);

        return [] === $distances ? 0.0 : min($distances);
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return list<array{0: int, 1: int}>
     */
    private function outline(array $element, LocalProjection $projection): array
    {
        $points = [];
        foreach (is_array($element['geometry'] ?? null) ? $element['geometry'] : [] as $node) {
            if (is_array($node) && isset($node['lat'], $node['lon'])) {
                $points[] = $projection->toPlan((float) $node['lat'], (float) $node['lon']);
            }
        }
        // OSM ways close by repeating the first node
        if (count($points) > 1 && $points[0] === end($points)) {
            array_pop($points);
        }
        if (count($points) > self::MAX_POINTS) {
            $step = count($points) / self::MAX_POINTS;
            $points = array_map(static fn (int $i): array => $points[(int) floor($i * $step)], range(0, self::MAX_POINTS - 1));
        }

        return $points;
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function plotRank(array $tags): int
    {
        foreach (self::PLOT_PRIORITY as $key => $rank) {
            if (isset($tags[$key])) {
                return $rank;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function tag(array $tags, string $key): string
    {
        return is_string($tags[$key] ?? null) ? strtolower(trim($tags[$key])) : '';
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function height(array $tags, float $defaultMetres): int
    {
        $height = is_numeric($tags[self::TAG_HEIGHT] ?? null) ? (float) $tags[self::TAG_HEIGHT] : $defaultMetres;

        return (int) round($height * self::MILLIMETRES_PER_METRE);
    }

    /**
     * Crown diameter in millimetres, 0 when not recorded.
     *
     * @param array<string, mixed> $tags
     */
    private function crown(array $tags): int
    {
        return is_numeric($tags[self::TAG_CROWN] ?? null) ? (int) round((float) $tags[self::TAG_CROWN] * self::MILLIMETRES_PER_METRE) : 0;
    }

    /**
     * "Spitz-Ahorn, 1982" for a registered tree.
     *
     * @param array<string, mixed> $tags
     */
    private function treeLabel(array $tags): ?string
    {
        $parts = array_filter([$this->text($tags, self::TAG_SPECIES), $this->text($tags, self::TAG_PLANTED)]);

        return [] === $parts ? null : implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function text(array $tags, string $key): ?string
    {
        return is_string($tags[$key] ?? null) && '' !== trim($tags[$key]) ? trim($tags[$key]) : null;
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function name(array $tags): ?string
    {
        return is_string($tags[self::TAG_NAME] ?? null) ? $tags[self::TAG_NAME] : null;
    }
}

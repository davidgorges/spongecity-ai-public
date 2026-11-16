<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Conditions\InfiltrationPotential;
use App\SpongeCity\Conditions\SiteConditions;
use App\SpongeCity\Geo\BuildingTags;
use App\SpongeCity\Geo\DataSource;
use App\SpongeCity\Geo\GeoBox;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\LocalProjection;
use App\SpongeCity\Geo\SiteImporter;
use App\SpongeCity\Geo\Utm;
use App\SpongeCity\Terrain\Terrain;
use App\SpongeCity\Terrain\TerrainGrid;
use Core\Configurator\Site\SiteObject;
use Psr\Log\LoggerInterface;

/**
 * Hamburg's official geodata where it is better than OpenStreetMap: inside the city,
 * the buildings come from the cadastre and the 3D building model, the street trees from
 * the tree register, and the infiltration potential map describes the ground. Each layer
 * replaces its OpenStreetMap counterpart on its own; when a service fails, that layer
 * stays as OpenStreetMap has it.
 *
 * Works on Overpass-like elements, so SiteImporter and SurroundingsImporter stay
 * unaware of where a building or tree came from.
 */
final readonly class HamburgGeodata
{
    public const string DATASET_INFILTRATION = 'versickerung';
    public const string COLLECTION_INFILTRATION = 'versickerungspotential';

    public const string LICENCE = 'Datenlizenz Deutschland – Namensnennung – Version 2.0 (dl-de/by-2-0)';
    public const string SOURCE_BUILDINGS = 'buildings';
    public const string SOURCE_TREES = 'trees';
    public const string SOURCE_INFILTRATION = 'infiltration';
    public const string SOURCE_TERRAIN = 'terrain';

    /** the site's grid: past the lawn (112 m) at 2 m; the surroundings' grid: 4 m, a bit past where the heights fade out */
    public const float FINE_HALF_METRES = 120.0;
    public const float FINE_SPACING_METRES = 2.0;
    public const float COARSE_HALF_METRES = 360.0;
    public const float COARSE_SPACING_METRES = 4.0;
    private const float CENTIMETRES_PER_METRE = 100.0;
    /** a step used to measure how plan metres map onto UTM here (grid convergence, scale) */
    private const float AFFINE_STEP_METRES = 100.0;

    /** the city's extent (mainland and harbour), generously */
    private const float WEST = 9.70;
    private const float EAST = 10.34;
    private const float SOUTH = 53.38;
    private const float NORTH = 53.75;

    /** an OpenStreetMap tree this close to a registered one is the same tree */
    private const float SAME_TREE_METRES = 3.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const int DECIMALS = 1;

    public function __construct(
        private HamburgApi $api,
        private HamburgBuildings $buildings,
        private HamburgTrees $trees,
        private HamburgElevation $elevation,
        private LoggerInterface $logger,
    ) {
    }

    public function covers(GeoPoint $point): bool
    {
        return (new GeoBox(self::WEST, self::SOUTH, self::EAST, self::NORTH))->contains($point);
    }

    /**
     * The elements of a site with Hamburg's buildings and street trees in place of
     * OpenStreetMap's, and the sources used.
     *
     * @param list<array<string, mixed>> $elements raw Overpass elements
     *
     * @return array{list<array<string, mixed>>, list<DataSource>}
     */
    public function site(GeoPoint $centre, array $elements, int $radiusMetres): array
    {
        if (!$this->covers($centre)) {
            return [$elements, []];
        }
        $sources = [];
        // the site keeps buildings out to its reach (cut there), trees within the radius
        $buildings = $this->layer(self::SOURCE_BUILDINGS, fn (): array => $this->buildings->within(GeoBox::around($centre, SiteObject::REACH / self::MILLIMETRES_PER_METRE)));
        if (null !== $buildings) {
            $elements = [...array_values(array_filter($elements, static fn (array $e): bool => !self::isBuilding($e))), ...$buildings];
            $sources[] = self::buildingsSource();
        }
        $trees = $this->layer(self::SOURCE_TREES, fn (): array => $this->trees->within(GeoBox::around($centre, $radiusMetres)));
        if (null !== $trees) {
            // the register knows street trees only: trees in yards and parks stay as mapped
            $elements = [...array_values(array_filter($elements, static fn (array $e): bool => !self::isTree($e) || !self::nearAny($e, $trees))), ...$trees];
            $sources[] = self::treesSource();
        }

        return [$elements, $sources];
    }

    /**
     * The surroundings' elements with Hamburg's buildings in place of OpenStreetMap's.
     *
     * @param list<array<string, mixed>> $elements raw Overpass ways
     *
     * @return list<array<string, mixed>>
     */
    public function surroundings(GeoPoint $centre, array $elements, int $radiusMetres): array
    {
        if (!$this->covers($centre)) {
            return $elements;
        }
        $buildings = $this->layer(self::SOURCE_BUILDINGS, fn (): array => $this->buildings->within(GeoBox::around($centre, $radiusMetres)));

        return null === $buildings ? $elements : [...array_values(array_filter($elements, static fn (array $e): bool => !self::isBuilding($e))), ...$buildings];
    }

    /**
     * The infiltration potential around a site, in plan metres.
     *
     * @param list<DataSource> $sources the sources of the site's other layers
     */
    public function conditions(GeoPoint $centre, int $radiusMetres, array $sources = []): SiteConditions
    {
        if (!$this->covers($centre)) {
            return new SiteConditions([], $sources);
        }
        $features = $this->layer(self::SOURCE_INFILTRATION, fn (): array => $this->api->features(self::DATASET_INFILTRATION, self::COLLECTION_INFILTRATION, GeoBox::around($centre, $radiusMetres)));
        if (null === $features) {
            return new SiteConditions([], $sources);
        }
        $projection = new LocalProjection($centre);
        $areas = [];
        $toPlan = static fn (array $ring): array => array_map(static function (array $point) use ($projection): array {
            [$x, $z] = $projection->toPlan((float) $point[1], (float) $point[0]);

            return [round($x / self::MILLIMETRES_PER_METRE, self::DECIMALS), round($z / self::MILLIMETRES_PER_METRE, self::DECIMALS)];
        }, $ring);
        foreach ($features as $feature) {
            $p = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $text = static fn (string $key): string => is_string($p[$key] ?? null) ? $p[$key] : '';
            foreach (self::polygons($feature['geometry'] ?? null) as [$outer, $holes]) {
                // the map's areas reach far beyond the site (some with hundreds of holes): only the part around it is kept
                $points = self::clipToSquare($toPlan($outer), $radiusMetres);
                if (count($points) < 3) {
                    continue;
                }
                $areas[] = [
                    SiteConditions::POINTS => $points,
                    SiteConditions::HOLES => array_values(array_filter(
                        array_map(static fn (array $hole): array => self::clipToSquare($toPlan($hole), $radiusMetres), $holes),
                        static fn (array $hole): bool => count($hole) >= 3,
                    )),
                    SiteConditions::POTENTIAL => InfiltrationPotential::fromGerman($text('versickerungswahrscheinlichkeit'))->value,
                    SiteConditions::DEPTH => $text('versickerungsfaehige_tiefe'),
                    SiteConditions::FACILITY => $text('versickerungsanlage'),
                ];
            }
        }

        return new SiteConditions($areas, [...$sources, self::infiltrationSource()]);
    }

    /**
     * The ground around a site from the terrain model (DGM1), null outside Hamburg or
     * when the download fails (the view stays flat). Every node is the mean of the 1 m
     * heights within its cell, so the grids are smooth enough to drape ground layers on.
     */
    public function terrain(GeoPoint $centre): ?Terrain
    {
        if (!$this->covers($centre)) {
            return null;
        }
        try {
            // plan metres → UTM is affine over a few hundred metres: the origin and two steps
            $projection = new LocalProjection($centre);
            $utm = static fn (float $x, float $z): array => Utm::fromGeo(...$projection->toGeo($x, $z));
            [$e0, $n0] = $utm(0, 0);
            [$ex, $nx] = $utm(self::AFFINE_STEP_METRES, 0);
            [$ez, $nz] = $utm(0, self::AFFINE_STEP_METRES);
            $toUtm = static fn (float $x, float $z): array => [
                $e0 + ($ex - $e0) * $x / self::AFFINE_STEP_METRES + ($ez - $e0) * $z / self::AFFINE_STEP_METRES,
                $n0 + ($nx - $n0) * $x / self::AFFINE_STEP_METRES + ($nz - $n0) * $z / self::AFFINE_STEP_METRES,
            ];
            $reach = self::COARSE_HALF_METRES + self::COARSE_SPACING_METRES;
            $corners = array_map(static fn (array $c): array => $toUtm($c[0] * $reach, $c[1] * $reach), [[-1, -1], [1, -1], [1, 1], [-1, 1]]);
            $tiles = $this->elevation->tiles(min(array_column($corners, 0)), min(array_column($corners, 1)), max(array_column($corners, 0)), max(array_column($corners, 1)));
            if ([] === $tiles) {
                return null;
            }
            $sample = static function (float $x, float $z) use ($toUtm, $tiles): ?float {
                [$e, $n] = $toUtm($x, $z);
                $tile = $tiles[intdiv((int) floor($e), 1000).'_'.intdiv((int) floor($n), 1000)] ?? null;

                return $tile?->heightAt($e, $n);
            };
            $reference = self::cellMean($sample, 0.0, 0.0, self::FINE_SPACING_METRES);
            if (null === $reference) {
                return null;
            }

            return new Terrain(
                $reference,
                self::grid($sample, $reference, self::FINE_HALF_METRES, self::FINE_SPACING_METRES),
                self::grid($sample, $reference, self::COARSE_HALF_METRES, self::COARSE_SPACING_METRES),
                self::terrainSource(),
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Hamburg terrain model unavailable, the ground stays flat', ['exception' => $exception]);

            return null;
        }
    }

    /**
     * @param callable(float, float): ?float $sample metres above sea level at plan metres
     */
    private static function grid(callable $sample, float $reference, float $half, float $spacing): TerrainGrid
    {
        $count = (int) round(2 * $half / $spacing) + 1;
        $heights = [];
        for ($row = 0; $row < $count; ++$row) {
            for ($column = 0; $column < $count; ++$column) {
                $mean = self::cellMean($sample, -$half + $column * $spacing, -$half + $row * $spacing, $spacing);
                // no data (a gap in the model): the origin's level
                $heights[] = null === $mean ? 0 : (int) round(($mean - $reference) * self::CENTIMETRES_PER_METRE);
            }
        }

        return new TerrainGrid(-$half, -$half, $spacing, $count, $count, $heights);
    }

    /**
     * The mean of the 1 m heights in a cell around a node.
     *
     * @param callable(float, float): ?float $sample
     */
    private static function cellMean(callable $sample, float $x, float $z, float $spacing): ?float
    {
        $steps = max(1, (int) round($spacing));
        $sum = 0.0;
        $n = 0;
        for ($i = 0; $i < $steps; ++$i) {
            for ($j = 0; $j < $steps; ++$j) {
                $h = $sample($x + ($i + 0.5) - $steps / 2, $z + ($j + 0.5) - $steps / 2);
                if (null !== $h) {
                    $sum += $h;
                    ++$n;
                }
            }
        }

        return $n > 0 ? $sum / $n : null;
    }

    public static function terrainSource(): DataSource
    {
        return new DataSource(self::SOURCE_TERRAIN, 'Digitales Geländemodell DGM1 Hamburg', 'Freie und Hansestadt Hamburg, Landesbetrieb Geoinformation und Vermessung (LGV)', HamburgElevation::AS_OF, self::LICENCE, HamburgElevation::ZIP_URL,
            sprintf('Bare-earth model from airborne laser scanning (1 m, about ±15 cm on open ground), averaged to %.0f m on the site and %.0f m around it. Heights above sea level (DHHN2016). Kerbs, gutters and drains are below its resolution.', self::FINE_SPACING_METRES, self::COARSE_SPACING_METRES));
    }

    public static function buildingsSource(): DataSource
    {
        return new DataSource(self::SOURCE_BUILDINGS, 'ALKIS Gebäude + 3D-Gebäudemodell LoD2-DE Hamburg', 'Freie und Hansestadt Hamburg, Landesbetrieb Geoinformation und Vermessung (LGV)', 'current (LoD2 2026)', self::LICENCE, HamburgApi::BASE_URL.'/'.HamburgBuildings::DATASET_LOD2,
            'Official footprints, uses and storeys; heights measured (LoD2). Roofs are drawn as simple gable, hip or flat roofs, not the full LoD2 geometry.');
    }

    public static function treesSource(): DataSource
    {
        return new DataSource(self::SOURCE_TREES, 'Straßenbaumkataster Hamburg', 'Freie und Hansestadt Hamburg, Behörde für Umwelt, Klima, Energie und Agrarwirtschaft (BUKEA)', 'updated yearly', self::LICENCE, HamburgApi::BASE_URL.'/'.HamburgTrees::DATASET,
            sprintf('Street trees only (trees in yards and parks come from OpenStreetMap). Crown diameters are recorded; heights are estimated as %.1f × crown + %.0f m.', HamburgTrees::HEIGHT_PER_CROWN, HamburgTrees::TRUNK_METRES));
    }

    public static function infiltrationSource(): DataSource
    {
        return new DataSource(self::SOURCE_INFILTRATION, 'Versickerungspotentialkarte Hamburg', 'Freie und Hansestadt Hamburg, Behörde für Umwelt, Klima, Energie und Agrarwirtschaft (BUKEA)', '2024', self::LICENCE, HamburgApi::BASE_URL.'/'.self::DATASET_INFILTRATION,
            'A preliminary assessment for planning, not a substitute for a percolation test; it gives no infiltration rate, and says nothing about contamination, utilities or building distances.');
    }

    /**
     * Every part of a (Multi)Polygon as its outer ring and its holes, without closing points.
     *
     * @return list<array{list<array{0: float, 1: float}>, list<list<array{0: float, 1: float}>>}> [longitude, latitude]
     */
    private static function polygons(mixed $geometry): array
    {
        if (!is_array($geometry) || !is_array($geometry['coordinates'] ?? null)) {
            return [];
        }
        $parts = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => [],
        };
        $polygons = [];
        foreach ($parts as $rings) {
            $rings = array_map(static function (mixed $ring): array {
                $ring = is_array($ring) ? array_values(array_filter($ring, static fn (mixed $p): bool => is_array($p) && isset($p[0], $p[1]))) : [];
                if (count($ring) > 1 && $ring[0] === end($ring)) {
                    array_pop($ring);
                }

                return $ring;
            }, is_array($rings) ? array_values($rings) : []);
            if (count($rings[0] ?? []) >= 3) {
                $polygons[] = [$rings[0], array_slice($rings, 1)];
            }
        }

        return $polygons;
    }

    /**
     * The part of a closed outline inside a square around the origin (Sutherland-Hodgman).
     *
     * @param list<array{0: float, 1: float}> $points
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function clipToSquare(array $points, float $half): array
    {
        foreach ([[0, 1], [0, -1], [1, 1], [1, -1]] as [$axis, $sign]) {
            $inside = static fn (array $p): bool => $sign * $p[$axis] <= $half;
            $clipped = [];
            for ($i = 0, $count = count($points); $i < $count; ++$i) {
                [$current, $previous] = [$points[$i], $points[($i + $count - 1) % $count]];
                if ($inside($current) !== $inside($previous)) {
                    $t = ($sign * $half - $previous[$axis]) / ($current[$axis] - $previous[$axis]);
                    $clipped[] = [round($previous[0] + $t * ($current[0] - $previous[0]), self::DECIMALS), round($previous[1] + $t * ($current[1] - $previous[1]), self::DECIMALS)];
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
     * @param callable(): list<array<string, mixed>> $fetch
     *
     * @return list<array<string, mixed>>|null null when the service failed (the layer stays as it was)
     */
    private function layer(string $name, callable $fetch): ?array
    {
        try {
            return $fetch();
        } catch (\Throwable $exception) {
            $this->logger->warning('Hamburg geodata unavailable, keeping OpenStreetMap', ['layer' => $name, 'exception' => $exception]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $element
     */
    private static function isBuilding(array $element): bool
    {
        return 'way' === ($element['type'] ?? null) && isset($element['tags'][BuildingTags::TAG_BUILDING]);
    }

    /**
     * @param array<string, mixed> $element
     */
    private static function isTree(array $element): bool
    {
        return 'node' === ($element['type'] ?? null) && 'tree' === ($element['tags'][SiteImporter::TAG_NATURAL] ?? null);
    }

    /**
     * @param array<string, mixed>       $node
     * @param list<array<string, mixed>> $others
     */
    private static function nearAny(array $node, array $others): bool
    {
        if (!isset($node['lat'], $node['lon'])) {
            return false;
        }
        $projection = new LocalProjection(new GeoPoint((float) $node['lat'], (float) $node['lon']));
        foreach ($others as $other) {
            [$x, $z] = $projection->toPlan((float) $other['lat'], (float) $other['lon']);
            if (hypot($x, $z) <= self::SAME_TREE_METRES * self::MILLIMETRES_PER_METRE) {
                return true;
            }
        }

        return false;
    }
}

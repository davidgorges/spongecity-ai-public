<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\BuildingTags;
use App\SpongeCity\Geo\GeoBox;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\LocalProjection;
use App\SpongeCity\Geo\SiteImporter;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;

/**
 * Hamburg's official buildings: the footprints, uses and storeys of the cadastre
 * (ALKIS, "GebaeudeBauwerk") joined with the heights and roof forms of the 3D building
 * model (LoD2-DE) by their shared ALKIS id. Returned as Overpass-like ways with
 * OpenStreetMap tags, so BuildingTags classifies them like any other building.
 *
 * The LoD2 model's vertices are quantised to whole metres by the API, which is too
 * coarse for footprints but fine for an eaves height; its measured height is exact.
 *
 * A flat-roofed building whose roof has several levels (a low wing, the main roof, a
 * recessed top storey, a stair core) carries them as `parts`: one outline and height per
 * roof surface, from the detailed LoD3.0-HH model on the site (matched by id, or by
 * position where its ids differ) or else from LoD2's full-precision CityGML. Drawn as one
 * block at the measured height (the highest point), a two-storey wing would stand as
 * tall as the tower beside it.
 */
final readonly class HamburgBuildings
{
    public const string DATASET_ALKIS = 'alkis_vereinfacht';
    public const string COLLECTION_ALKIS = 'GebaeudeBauwerk';
    public const string DATASET_LOD2 = 'lod2_hamburg';
    public const string COLLECTION_LOD2 = 'building';

    /** tags set besides OpenStreetMap's own */
    public const string TAG_ALKIS_ID = BuildingTags::TAG_ALKIS_ID;
    public const string TAG_FUNCTION = SiteImporter::TAG_FUNCTION;
    public const string TAG_SOURCE = 'source';
    public const string SOURCE = 'ALKIS + LoD2-DE Hamburg';

    /** ALKIS ids of buildings end in this, LoD2 ids are the same without it */
    private const string ALKIS_SUFFIX = 'BL';
    private const string KIND_BUILDING = 'Gebaeude';
    private const string BELOW_GROUND = 'Unter der Erdoberfläche';
    private const string GROUND_SURFACE = 'GroundSurface';
    private const string ROOF_SURFACE = 'RoofSurface';
    /** below this, an eaves height read from the coarse LoD2 vertices is not believed */
    private const float MIN_EAVES_METRES = 2.0;
    private const int SMALL_HOUSE_STOREYS = 2;
    /** roof levels closer than this are one level */
    private const float LEVEL_STEP_METRES = 1.5;
    /** roof surfaces smaller than this (railing tops, hatches) are left out */
    private const float MIN_SURFACE_SQUARE_METRES = 1.0;
    /** a surface rising less than this is flat */
    private const float FLAT_SURFACE_METRES = 0.5;
    /** parts are drawn for roofs this flat at least (share of the area): pitched roofs keep their gable or hip */
    private const float MIN_FLAT_SHARE = 0.8;
    private const int MAX_PARTS = 40;
    private const float METRES_PER_DEGREE = 111_195.0;
    /** the element key the parts travel under (SiteImporter, SurroundingsImporter) */
    public const string PARTS = SiteImporter::ELEMENT_PARTS;

    /** ALKIS Dachform (LoD2 roofType) → roof:shape */
    private const array ROOF_SHAPES = [
        '1000' => 'flat', '2100' => 'skillion', '2200' => 'skillion', '3100' => 'gabled', '3200' => 'hipped',
        '3300' => 'half-hipped', '3400' => 'mansard', '3500' => 'pyramidal', '3600' => 'pyramidal', '4000' => 'pyramidal',
    ];
    private const string FLAT = 'flat';
    /** words in the ALKIS function (German) → building=*, the first match wins */
    private const array FUNCTIONS = [
        'Kindertages' => 'kindergarten', 'Kindergarten' => 'kindergarten', 'Schule' => 'school', 'Hochschul' => 'university',
        'Universit' => 'university', 'Wohnhaus' => 'house', 'Wohn' => 'apartments', 'Hotel' => 'hotel', 'Büro' => 'office',
        'Verwaltung' => 'office', 'Handel' => 'retail', 'Geschäft' => 'retail', 'Kauf' => 'retail', 'Parkhaus' => 'industrial',
        'Garage' => 'garage', 'Carport' => 'carport', 'Schuppen' => 'shed', 'Gartenhaus' => 'shed', 'Turnhalle' => 'sports_hall',
        'Sport' => 'sports_hall', 'Kirche' => 'church', 'religiös' => 'church', 'Krankenhaus' => 'hospital', 'Feuerwehr' => 'fire_station',
        'Bahnhof' => 'train_station', 'Tankstelle' => 'service', 'Werkstatt' => 'industrial', 'Wasch' => 'industrial',
        'Industrie' => 'industrial', 'Produktion' => 'industrial', 'Lager' => 'warehouse', 'Fabrik' => 'industrial',
    ];
    private const string UNKNOWN_USE = 'yes';

    public function __construct(
        private HamburgApi $api,
        private ?HamburgCityGml $cityGml = null,
        private ?HamburgLod3 $lod3 = null,
    ) {
    }

    /**
     * @param bool $detailed with the detailed LoD3 roofs (the site; too heavy for the wide surroundings)
     *
     * @return list<array<string, mixed>> Overpass-like ways (type, geometry of lat/lon, tags, parts)
     */
    public function within(GeoBox $box, bool $detailed = false): array
    {
        $models = $this->api->cityObjects(self::DATASET_LOD2, self::COLLECTION_LOD2, $box);
        $centre = new GeoPoint(($box->south + $box->north) / 2, ($box->west + $box->east) / 2);
        $half = ($box->north - $box->south) / 2 * self::METRES_PER_DEGREE;
        $lod2 = [];
        foreach ($this->models(fn (): array => $this->cityGml?->roofSurfaces($centre, $half) ?? []) as $model) {
            $lod2[$model['id']] = $model;
        }
        $lod3 = $detailed ? $this->models(fn (): array => $this->lod3?->roofSurfaces($centre, $half) ?? []) : [];
        $projection = new LocalProjection($centre);
        $ways = [];
        foreach ($this->api->features(self::DATASET_ALKIS, self::COLLECTION_ALKIS, $box) as $feature) {
            $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            if (self::KIND_BUILDING !== ($properties['gebnutzbez'] ?? null) || self::BELOW_GROUND === ($properties['rellage'] ?? null)) {
                continue;
            }
            $ring = self::outerRing($feature['geometry'] ?? null);
            if (count($ring) < 3) {
                continue;
            }
            $id = is_string($properties['oid'] ?? null) ? $properties['oid'] : '';
            $tags = $this->tags($properties, $models[self::lod2Id($id)] ?? null);
            $way = [
                'type' => 'way',
                'geometry' => array_map(static fn (array $p): array => ['lat' => (float) $p[1], 'lon' => (float) $p[0]], $ring),
                'tags' => $tags,
            ];
            if (self::FLAT === ($tags[BuildingTags::TAG_ROOF_SHAPE] ?? null)) {
                $outline = array_map(static function (array $p) use ($projection): array {
                    [$x, $z] = $projection->toPlan((float) $p[1], (float) $p[0]);

                    return [$x / 1000, $z / 1000];
                }, $ring);
                $model = self::match(self::lod2Id($id), $outline, $lod3) ?? $lod2[self::lod2Id($id)] ?? null;
                $parts = null === $model ? [] : self::parts($model['surfaces']);
                if ([] !== $parts) {
                    $way[self::PARTS] = array_map(static fn (array $part): array => [
                        'geometry' => array_map(static function (array $p) use ($projection): array {
                            [$lat, $lon] = $projection->toGeo($p[0], $p[1]);

                            return ['lat' => $lat, 'lon' => $lon];
                        }, $part['points']),
                        'height' => $part['height'],
                    ], $parts);
                }
            }
            $ways[] = $way;
        }

        return $ways;
    }

    /**
     * The roof levels of a flat roof as parts (largest first), when it has more than one;
     * none for a single level or a pitched roof (drawn from the outline as before).
     *
     * @param list<array{points: list<array{0: float, 1: float}>, low: float, high: float}> $surfaces plan metres
     *
     * @return list<array{points: list<array{0: float, 1: float}>, height: float}>
     */
    private static function parts(array $surfaces): array
    {
        $surfaces = array_values(array_filter($surfaces, static fn (array $s): bool => $s['high'] > 0 && CityGmlRoofs::area($s['points']) >= self::MIN_SURFACE_SQUARE_METRES));
        if (count($surfaces) < 2) {
            return [];
        }
        $areas = array_map(static fn (array $s): float => CityGmlRoofs::area($s['points']), $surfaces);
        $flat = array_sum(array_map(static fn (array $s, float $a): float => $s['high'] - $s['low'] < self::FLAT_SURFACE_METRES ? $a : 0.0, $surfaces, $areas));
        $heights = array_column($surfaces, 'high');
        if ($flat < self::MIN_FLAT_SHARE * array_sum($areas) || max($heights) - min($heights) < self::LEVEL_STEP_METRES) {
            return [];
        }
        array_multisort($areas, \SORT_DESC, $surfaces);

        return array_map(static fn (array $s): array => ['points' => $s['points'], 'height' => round($s['high'], 2)], array_slice($surfaces, 0, self::MAX_PARTS));
    }

    /**
     * The model of a cadastre building: by its id, else the one whose middle lies in its outline.
     *
     * @param list<array{0: float, 1: float}>                                                             $outline plan metres
     * @param list<array{id: string, centre: array{0: float, 1: float}, surfaces: list<array<string, mixed>>}> $models
     *
     * @return array{id: string, centre: array{0: float, 1: float}, surfaces: list<array{points: list<array{0: float, 1: float}>, low: float, high: float}>}|null
     */
    private static function match(string $id, array $outline, array $models): ?array
    {
        foreach ($models as $model) {
            if ($model['id'] === $id) {
                return $model;
            }
        }
        foreach ($models as $model) {
            if (Polygon::contains($outline, $model['centre'][0], $model['centre'][1])) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Building models from a reader; none when it fails (the outline is drawn instead).
     *
     * @param callable(): list<array{id: string, centre: array{0: float, 1: float}, surfaces: list<array<string, mixed>>}> $read
     *
     * @return list<array{id: string, centre: array{0: float, 1: float}, surfaces: list<array<string, mixed>>}>
     */
    private function models(callable $read): array
    {
        try {
            return $read();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed>      $properties ALKIS
     * @param array<string, mixed>|null $model      LoD2 city object
     *
     * @return array<string, string>
     */
    private function tags(array $properties, ?array $model): array
    {
        $function = is_string($properties['funktion'] ?? null) ? $properties['funktion'] : '';
        $storeys = is_numeric($properties['anzahlgs'] ?? null) ? (int) $properties['anzahlgs'] : null;
        $tags = [
            BuildingTags::TAG_BUILDING => $this->use($function, $storeys),
            self::TAG_FUNCTION => $function,
            self::TAG_ALKIS_ID => is_string($properties['oid'] ?? null) ? $properties['oid'] : '',
            self::TAG_SOURCE => self::SOURCE,
        ];
        if (null !== $storeys) {
            $tags[BuildingTags::TAG_LEVELS] = (string) $storeys;
        }
        $attributes = is_array($model['attributes'] ?? null) ? $model['attributes'] : [];
        $roof = self::ROOF_SHAPES[(string) ($attributes['roofType'] ?? '')] ?? null;
        if (null !== $roof) {
            $tags[BuildingTags::TAG_ROOF_SHAPE] = $roof;
        }
        $height = is_numeric($attributes['measuredHeight'] ?? null) ? (float) $attributes['measuredHeight'] : null;
        if (null !== $height) {
            // a pitched roof sits on the walls: the site's height of such a building is its eaves
            $tags[BuildingTags::TAG_HEIGHT] = (string) round(self::FLAT === $roof || null === $roof ? $height : (self::eaves($model) ?? $height), 1);
        }

        return $tags;
    }

    /**
     * Eaves height above the ground: the lowest point of the roof over the lowest of the ground.
     *
     * @param array<string, mixed>|null $model
     */
    private static function eaves(?array $model): ?float
    {
        $ground = $model['zRange'][self::GROUND_SURFACE][0] ?? null;
        $roof = $model['zRange'][self::ROOF_SURFACE][0] ?? null;
        if (!is_numeric($ground) || !is_numeric($roof)) {
            return null;
        }
        $eaves = (float) $roof - (float) $ground;

        return $eaves >= self::MIN_EAVES_METRES ? $eaves : null;
    }

    private function use(string $function, ?int $storeys): string
    {
        foreach (self::FUNCTIONS as $word => $use) {
            if (str_contains($function, $word)) {
                return 'house' === $use && null !== $storeys && $storeys > self::SMALL_HOUSE_STOREYS ? 'apartments' : $use;
            }
        }

        return self::UNKNOWN_USE;
    }

    private static function lod2Id(string $alkisId): string
    {
        return str_ends_with($alkisId, self::ALKIS_SUFFIX) ? substr($alkisId, 0, -strlen(self::ALKIS_SUFFIX)) : $alkisId;
    }

    /**
     * The outer ring of a (Multi)Polygon, of its largest part, without the closing point.
     *
     * @return list<array{0: float, 1: float}> [longitude, latitude]
     */
    public static function outerRing(mixed $geometry): array
    {
        if (!is_array($geometry) || !is_array($geometry['coordinates'] ?? null)) {
            return [];
        }
        $polygons = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => [],
        };
        $best = [];
        $bestArea = -1.0;
        foreach ($polygons as $polygon) {
            $ring = is_array($polygon[0] ?? null) ? array_values(array_filter($polygon[0], static fn (mixed $p): bool => is_array($p) && isset($p[0], $p[1]))) : [];
            $area = 0.0;
            for ($i = 0, $n = count($ring); $i < $n; ++$i) {
                $next = $ring[($i + 1) % $n];
                $area += (float) $ring[$i][0] * (float) $next[1] - (float) $next[0] * (float) $ring[$i][1];
            }
            if (abs($area) > $bestArea) {
                [$best, $bestArea] = [$ring, abs($area)];
            }
        }
        if (count($best) > 1 && $best[0] === end($best)) {
            array_pop($best);
        }

        return $best;
    }
}

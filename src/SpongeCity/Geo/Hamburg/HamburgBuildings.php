<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\BuildingTags;
use App\SpongeCity\Geo\GeoBox;
use App\SpongeCity\Geo\SiteImporter;

/**
 * Hamburg's official buildings: the footprints, uses and storeys of the cadastre
 * (ALKIS, "GebaeudeBauwerk") joined with the heights and roof forms of the 3D building
 * model (LoD2-DE) by their shared ALKIS id. Returned as Overpass-like ways with
 * OpenStreetMap tags, so BuildingTags classifies them like any other building.
 *
 * The LoD2 model's vertices are quantised to whole metres by the API, which is too
 * coarse for footprints but fine for an eaves height; its measured height is exact.
 */
final readonly class HamburgBuildings
{
    public const string DATASET_ALKIS = 'alkis_vereinfacht';
    public const string COLLECTION_ALKIS = 'GebaeudeBauwerk';
    public const string DATASET_LOD2 = 'lod2_hamburg';
    public const string COLLECTION_LOD2 = 'building';

    /** tags set besides OpenStreetMap's own */
    public const string TAG_ALKIS_ID = 'ref:alkis';
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
    ) {
    }

    /**
     * @return list<array<string, mixed>> Overpass-like ways (type, geometry of lat/lon, tags)
     */
    public function within(GeoBox $box): array
    {
        $models = $this->api->cityObjects(self::DATASET_LOD2, self::COLLECTION_LOD2, $box);
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
            $ways[] = [
                'type' => 'way',
                'geometry' => array_map(static fn (array $p): array => ['lat' => (float) $p[1], 'lon' => (float) $p[0]], $ring),
                'tags' => $this->tags($properties, $models[self::lod2Id($id)] ?? null),
            ];
        }

        return $ways;
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

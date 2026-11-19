<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Geo;

use App\SpongeCity\Conditions\InfiltrationPotential;
use App\SpongeCity\Conditions\SiteConditions;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\Hamburg\Hamburg3dTiles;
use App\SpongeCity\Geo\Hamburg\HamburgApi;
use App\SpongeCity\Geo\Hamburg\HamburgBuildingLayer;
use App\SpongeCity\Geo\Hamburg\HamburgBuildings;
use App\SpongeCity\Geo\Hamburg\HamburgInfiltration;
use App\SpongeCity\Geo\Hamburg\HamburgTreeLayer;
use App\SpongeCity\Geo\Hamburg\HamburgTrees;
use App\SpongeCity\Geo\MapArea;
use App\SpongeCity\Geo\MapLayers;
use App\SpongeCity\Geo\SiteImporter;
use Core\Configurator\Scenery\Building;
use Core\Configurator\Site\BuildingType;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The Hamburg map layers and infiltration map against answers shaped like api.hamburg.de's (ALKIS, LoD2 CityJSON,
 * Straßenbaumkataster, Versickerungspotentialkarte), around the Brecht-Schule.
 */
final class HamburgLayersTest extends TestCase
{
    private const float LATITUDE = 53.5513;
    private const float LONGITUDE = 10.0188;
    /** about 7 m in degrees of latitude / longitude here */
    private const float D = 0.00006;
    private const float E = 0.0001;

    /** @var list<string> */
    private array $requested = [];

    #[Test]
    public function buildsTheSiteFromTheCadastreAndTheBuildingModel(): void
    {
        $osm = [
            $this->way(['building' => 'yes'], 3),
            ['type' => 'node', 'lat' => self::LATITUDE + 0.0002, 'lon' => self::LONGITUDE, 'tags' => ['natural' => 'tree']],
            // the same tree as the registered one, mapped a metre off
            ['type' => 'node', 'lat' => self::LATITUDE - 0.0003 + 0.00001, 'lon' => self::LONGITUDE, 'tags' => ['natural' => 'tree']],
        ];
        $map = $this->layers()->apply($osm, MapArea::site(new GeoPoint(self::LATITUDE, self::LONGITUDE), 80));
        $import = (new SiteImporter())->import(new GeoPoint(self::LATITUDE, self::LONGITUDE, 'Test'), $map->elements);
        $objects = json_decode((new SiteType())->normalize($import->siteJson), true, flags: \JSON_THROW_ON_ERROR)['objects'];

        $buildings = array_values(array_filter($objects, static fn (array $o): bool => O::BUILDING === $o[O::TYPE]));
        self::assertCount(2, $buildings, 'the OpenStreetMap building is replaced, the underground car park left out');
        $school = $buildings[0];
        self::assertSame(BuildingType::School->value, $school[O::USE]);
        self::assertSame('Allgemein bildende Schule', $school[O::LABEL]);
        self::assertSame(25100, $school[O::HEIGHT], 'the measured LoD2 height');
        self::assertSame(Building::ROOF_FLAT, $school[O::ROOF]);
        $house = $buildings[1];
        self::assertSame(Building::ROOF_GABLE, $house[O::ROOF]);
        self::assertSame(9000, $house[O::HEIGHT], 'a pitched roof stands on its eaves (roof 16 m − ground 7 m), not the ridge');

        $trees = array_values(array_filter($objects, static fn (array $o): bool => O::TREE === $o[O::TYPE]));
        self::assertCount(2, $trees, 'the registered tree, and the yard tree only OpenStreetMap knows');
        $registered = array_values(array_filter($trees, static fn (array $t): bool => null !== $t[O::LABEL]))[0];
        self::assertSame('Spitz-Ahorn, 1982', $registered[O::LABEL]);
        self::assertSame(7000, $registered[O::DIAMETER]);
        self::assertSame(14800, $registered[O::HEIGHT], 'measured by the laser scan (3D street tree register), not estimated from the crown');

        self::assertSame([HamburgBuildingLayer::SOURCE, HamburgTreeLayer::SOURCE], array_map(static fn ($s): string => $s->key, $map->sources));
    }

    #[Test]
    public function keepsOpenStreetMapWhenAServiceFails(): void
    {
        $osm = [$this->way(['building' => 'yes'], 3)];

        $map = $this->layers(failing: HamburgBuildings::DATASET_ALKIS)->apply($osm, MapArea::site(new GeoPoint(self::LATITUDE, self::LONGITUDE), 80));

        self::assertContains($osm[0], $map->elements);
        $tree = array_values(array_filter($map->elements, static fn (array $e): bool => HamburgTrees::SOURCE === ($e['tags']['source'] ?? null)))[0];
        self::assertSame(HamburgTrees::HEIGHT_MEASURED, $tree['tags'][HamburgTrees::TAG_HEIGHT_SOURCE]);
        self::assertSame([HamburgTreeLayer::SOURCE], array_map(static fn ($s): string => $s->key, $map->sources));
    }

    #[Test]
    public function estimatesTreeHeightsWhenThe3dLayerIsDown(): void
    {
        $map = $this->layers(failing: 'gdi3d')->apply([], MapArea::site(new GeoPoint(self::LATITUDE, self::LONGITUDE), 80));

        $tree = array_values(array_filter($map->elements, static fn (array $e): bool => HamburgTrees::SOURCE === ($e['tags']['source'] ?? null)))[0];
        self::assertSame((string) HamburgTrees::heightFromCrown(7), $tree['tags']['height']);
        self::assertSame(HamburgTrees::HEIGHT_ESTIMATED, $tree['tags'][HamburgTrees::TAG_HEIGHT_SOURCE]);
    }

    #[Test]
    public function asksNothingOutsideHamburg(): void
    {
        $osm = [$this->way(['building' => 'yes'], 3)];

        $map = $this->layers()->apply($osm, MapArea::site(new GeoPoint(52.52, 13.40), 80));

        self::assertSame($osm, $map->elements);
        self::assertSame([], $map->sources);
        self::assertSame([], $this->requested);
        self::assertSame([], (new HamburgInfiltration($this->api(), new NullLogger(), new ArrayAdapter()))->around(new GeoPoint(52.52, 13.40), 100)->infiltration);
    }

    #[Test]
    public function aroundTheSiteOnlyTheBuildingsAreReplaced(): void
    {
        $osm = [['type' => 'node', 'lat' => self::LATITUDE + 0.0002, 'lon' => self::LONGITUDE, 'tags' => ['natural' => 'tree']]];

        $map = $this->layers()->apply($osm, MapArea::surroundings(new GeoPoint(self::LATITUDE, self::LONGITUDE), 350));

        self::assertSame([HamburgBuildingLayer::SOURCE], array_map(static fn ($s): string => $s->key, $map->sources));
        self::assertContains($osm[0], $map->elements);
    }

    #[Test]
    public function mapsTheInfiltrationPotentialAroundTheSite(): void
    {
        $conditions = (new HamburgInfiltration($this->api(), new NullLogger(), new ArrayAdapter()))->around(new GeoPoint(self::LATITUDE, self::LONGITUDE), 100);

        self::assertCount(2, $conditions->infiltration);
        self::assertSame(InfiltrationPotential::Possible->value, $conditions->infiltration[0][SiteConditions::POTENTIAL]);
        self::assertSame(InfiltrationPotential::Unlikely->value, $conditions->infiltration[1][SiteConditions::POTENTIAL]);
        // the big area is cut to the 100 m around the site, its hole kept
        $xs = array_column($conditions->infiltration[0][SiteConditions::POINTS], 0);
        self::assertSame(100.0, max($xs));
        self::assertCount(1, $conditions->infiltration[0][SiteConditions::HOLES]);
        $sources = $conditions->sources;
        self::assertSame(HamburgInfiltration::SOURCE, end($sources)->key);

        // a 20 m square plot: the west half lies in the unlikely area (the hole), the rest in the possible one
        $shares = $conditions->infiltrationShares([[-10000, -10000], [10000, -10000], [10000, 10000], [-10000, 10000]]);
        self::assertEqualsWithDelta(0.5, $shares[InfiltrationPotential::Unlikely->value], 0.05);
        self::assertEqualsWithDelta(0.5, $shares[InfiltrationPotential::Possible->value], 0.05);
    }

    /**
     * The buildings, then the trees, as the container orders them.
     */
    private function layers(?string $failing = null): MapLayers
    {
        $api = $this->api($failing);
        $tiles = new Hamburg3dTiles($this->client($failing), new ArrayAdapter());

        return new MapLayers([new HamburgBuildingLayer(new HamburgBuildings($api)), new HamburgTreeLayer(new HamburgTrees($api, $tiles))], new NullLogger());
    }

    private function api(?string $failing = null): HamburgApi
    {
        return new HamburgApi($this->client($failing), new ArrayAdapter());
    }

    private function client(?string $failing): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url) use ($failing): MockResponse {
            $this->requested[] = $url;
            if (null !== $failing && str_contains($url, '/'.$failing.'/')) {
                return new MockResponse('', ['http_code' => 503]);
            }

            if (str_contains($url, 'tileset.json')) {
                return new MockResponse((string) json_encode($this->tileset()));
            }
            if (str_ends_with($url, '.i3dm')) {
                return new MockResponse(self::i3dm([['Baumnummer' => 'N160-3-1', 'Hoehe_aus_ALS' => 14.8], ['Baumnummer' => 'X-1', 'Hoehe_aus_ALS' => 9.0]]));
            }

            if (str_contains($url, '/'.HamburgInfiltration::DATASET.'/')) {
                // read a feature at a time: honour offset and limit
                parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
                $all = $this->infiltration();

                return new MockResponse((string) json_encode([...$all, 'features' => array_slice($all['features'], (int) ($query['offset'] ?? 0), (int) ($query['limit'] ?? 100))]));
            }

            return new MockResponse((string) json_encode(match (true) {
                str_contains($url, '/'.HamburgBuildings::DATASET_ALKIS.'/') => $this->alkis(),
                str_contains($url, '/'.HamburgBuildings::DATASET_LOD2.'/') => $this->lod2(),
                str_contains($url, '/'.HamburgTrees::DATASET.'/') => $this->trees(),
                str_contains($url, '/'.HamburgInfiltration::DATASET.'/') => $this->infiltration(),
                default => [],
            }));
        });
    }

    /**
     * @param array<string, string> $tags
     *
     * @return array<string, mixed>
     */
    private function way(array $tags, int $offset): array
    {
        $lat = self::LATITUDE + $offset * self::D;

        return ['type' => 'way', 'tags' => $tags, 'geometry' => [
            ['lat' => $lat, 'lon' => self::LONGITUDE], ['lat' => $lat, 'lon' => self::LONGITUDE + self::E],
            ['lat' => $lat + self::D, 'lon' => self::LONGITUDE + self::E], ['lat' => $lat + self::D, 'lon' => self::LONGITUDE],
            ['lat' => $lat, 'lon' => self::LONGITUDE],
        ]];
    }

    /**
     * @return list<list<float>> a closed ring of [lon, lat]
     */
    private function ring(float $lat, float $lon, float $dLat, float $dLon): array
    {
        return [[$lon, $lat], [$lon + $dLon, $lat], [$lon + $dLon, $lat + $dLat], [$lon, $lat + $dLat], [$lon, $lat]];
    }

    /**
     * @return array<string, mixed>
     */
    private function alkis(): array
    {
        $feature = fn (string $oid, string $kind, string $function, int $storeys, int $offset, array $extra = []): array => [
            'type' => 'Feature',
            'properties' => ['oid' => $oid, 'gebnutzbez' => $kind, 'funktion' => $function, 'anzahlgs' => $storeys, ...$extra],
            'geometry' => ['type' => 'MultiPolygon', 'coordinates' => [[$this->ring(self::LATITUDE + $offset * self::D, self::LONGITUDE, self::D, self::E)]]],
        ];

        return ['type' => 'FeatureCollection', 'features' => [
            $feature('DEHHALKA10005b0YBL', 'Gebaeude', 'Allgemein bildende Schule', 5, 1),
            $feature('DEHHALKAJ0000zAhBL', 'Gebaeude', 'Wohnhaus', 2, -2),
            $feature('DEHHALKA10005b0eBL', 'Gebaeude', 'Tiefgarage', 1, 4, ['rellage' => 'Unter der Erdoberfläche']),
            $feature('DEHHALKA10005b0bBL', 'Bauteil', 'Geringergeschossiger Gebäudeteil', 1, 1),
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function lod2(): array
    {
        $solid = static fn (int $roofType): array => ['type' => 'Solid', 'lod' => '2', 'boundaries' => [[[[0, 0, 0]], [[1, 1, 1]], [[2, 2, 2]]]],
            'semantics' => ['surfaces' => [['type' => 'GroundSurface'], ['type' => 'RoofSurface'], ['type' => 'RoofSurface']], 'values' => [[0, 1, 2]]]];

        return ['type' => 'CityJSON', 'transform' => ['scale' => [1, 1, 1], 'translate' => [0, 0, 80]], 'vertices' => [[0, 0, -73], [0, 0, -64], [0, 0, -61]],
            'CityObjects' => [
                'DEHHALKA10005b0Y' => ['type' => 'Building', 'attributes' => ['measuredHeight' => 25.1, 'roofType' => '1000'], 'geometry' => [$solid(1000)]],
                'DEHHALKAJ0000zAh' => ['type' => 'Building', 'attributes' => ['measuredHeight' => 12.0, 'roofType' => '3100'], 'geometry' => [$solid(3100)]],
            ]];
    }

    /**
     * A tileset whose root (around the site) refers to an external tileset with one leaf tile, as the city's do.
     *
     * @return array<string, mixed>
     */
    private function tileset(): array
    {
        $region = [deg2rad(self::LONGITUDE - 0.01), deg2rad(self::LATITUDE - 0.01), deg2rad(self::LONGITUDE + 0.01), deg2rad(self::LATITUDE + 0.01), 0, 50];
        $far = [deg2rad(self::LONGITUDE + 0.5), deg2rad(self::LATITUDE + 0.5), deg2rad(self::LONGITUDE + 0.6), deg2rad(self::LATITUDE + 0.6), 0, 50];

        return ['asset' => ['version' => '1.0'], 'root' => ['boundingVolume' => ['region' => $region], 'children' => [
            ['boundingVolume' => ['region' => $region], 'children' => [['boundingVolume' => ['region' => $region], 'content' => ['uri' => '../../17/1/1.i3dm']]]],
            // a tile far away is never fetched
            ['boundingVolume' => ['region' => $far], 'content' => ['uri' => 'far.json']],
        ]]];
    }

    /**
     * A minimal i3dm tile with a batch table in the city's form (one attribute set per feature).
     *
     * @param list<array<string, mixed>> $attributes
     */
    private static function i3dm(array $attributes): string
    {
        $feature = (string) json_encode(['INSTANCES_LENGTH' => count($attributes)]);
        $batch = (string) json_encode(['id' => array_keys($attributes), 'attributes' => $attributes]);

        return 'i3dm'.pack('V7', 1, 32 + strlen($feature) + strlen($batch), strlen($feature), 0, strlen($batch), 0, 0).$feature.$batch;
    }

    /**
     * @return array<string, mixed>
     */
    private function trees(): array
    {
        return ['type' => 'FeatureCollection', 'features' => [[
            'type' => 'Feature',
            'geometry' => ['type' => 'MultiPoint', 'coordinates' => [[self::LONGITUDE, self::LATITUDE - 0.0003]]],
            'properties' => ['baumnummer' => 'N160-3-1', 'art_deutsch' => 'Spitz-Ahorn', 'pflanzjahr' => 1982, 'kronendurchmesser' => 7],
        ]]];
    }

    /**
     * @return array<string, mixed>
     */
    private function infiltration(): array
    {
        $area = fn (string $class, array ...$rings): array => ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => $rings],
            'properties' => ['versickerungswahrscheinlichkeit' => $class, 'versickerungsanlage' => 'x', 'versickerungsfaehige_tiefe' => 'y']];
        // west of the centre line, just around the site
        $west = $this->ring(self::LATITUDE - 0.0005, self::LONGITUDE - 0.001, 0.001, 0.001);

        return ['type' => 'FeatureCollection', 'features' => [
            // a large area reaching far beyond the site, with a hole where the map says something else
            $area('möglich', $this->ring(self::LATITUDE - 0.01, self::LONGITUDE - 0.01, 0.02, 0.02), $west),
            $area('unwahrscheinlich', $west),
        ]];
    }
}

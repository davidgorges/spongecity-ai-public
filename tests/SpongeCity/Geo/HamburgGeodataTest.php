<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Geo;

use App\SpongeCity\Conditions\InfiltrationPotential;
use App\SpongeCity\Conditions\SiteConditions;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\Hamburg\HamburgApi;
use App\SpongeCity\Geo\Hamburg\HamburgBuildings;
use App\SpongeCity\Geo\Hamburg\HamburgElevation;
use App\SpongeCity\Geo\Hamburg\HamburgGeodata;
use App\SpongeCity\Geo\Hamburg\HamburgTrees;
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
 * The Hamburg layers against answers shaped like api.hamburg.de's (ALKIS, LoD2 CityJSON,
 * Straßenbaumkataster, Versickerungspotentialkarte), around the Brecht-Schule.
 */
final class HamburgGeodataTest extends TestCase
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
        [$elements, $sources] = $this->geodata()->site(new GeoPoint(self::LATITUDE, self::LONGITUDE), $osm, 80);
        $import = (new SiteImporter())->import(new GeoPoint(self::LATITUDE, self::LONGITUDE, 'Test'), $elements);
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
        self::assertSame((int) (HamburgTrees::heightFromCrown(7) * 1000), $registered[O::HEIGHT]);

        self::assertSame([HamburgGeodata::SOURCE_BUILDINGS, HamburgGeodata::SOURCE_TREES], array_map(static fn ($s): string => $s->key, $sources));
    }

    #[Test]
    public function keepsOpenStreetMapWhenAServiceFails(): void
    {
        $geodata = $this->geodata(failing: HamburgBuildings::DATASET_ALKIS);
        $osm = [$this->way(['building' => 'yes'], 3)];

        [$elements, $sources] = $geodata->site(new GeoPoint(self::LATITUDE, self::LONGITUDE), $osm, 80);

        self::assertContains($osm[0], $elements);
        self::assertSame([HamburgGeodata::SOURCE_TREES], array_map(static fn ($s): string => $s->key, $sources));
    }

    #[Test]
    public function asksNothingOutsideHamburg(): void
    {
        $osm = [$this->way(['building' => 'yes'], 3)];

        [$elements, $sources] = $this->geodata()->site(new GeoPoint(52.52, 13.40), $osm, 80);

        self::assertSame($osm, $elements);
        self::assertSame([], $sources);
        self::assertSame([], $this->requested);
    }

    #[Test]
    public function mapsTheInfiltrationPotentialAroundTheSite(): void
    {
        $conditions = $this->geodata()->conditions(new GeoPoint(self::LATITUDE, self::LONGITUDE), 100);

        self::assertCount(2, $conditions->infiltration);
        self::assertSame(InfiltrationPotential::Possible->value, $conditions->infiltration[0][SiteConditions::POTENTIAL]);
        self::assertSame(InfiltrationPotential::Unlikely->value, $conditions->infiltration[1][SiteConditions::POTENTIAL]);
        // the big area is cut to the 100 m around the site, its hole kept
        $xs = array_column($conditions->infiltration[0][SiteConditions::POINTS], 0);
        self::assertSame(100.0, max($xs));
        self::assertCount(1, $conditions->infiltration[0][SiteConditions::HOLES]);
        $sources = $conditions->sources;
        self::assertSame(HamburgGeodata::SOURCE_INFILTRATION, end($sources)->key);

        // a 20 m square plot: the west half lies in the unlikely area (the hole), the rest in the possible one
        $shares = $conditions->infiltrationShares([[-10000, -10000], [10000, -10000], [10000, 10000], [-10000, 10000]]);
        self::assertEqualsWithDelta(0.5, $shares[InfiltrationPotential::Unlikely->value], 0.05);
        self::assertEqualsWithDelta(0.5, $shares[InfiltrationPotential::Possible->value], 0.05);
    }

    private function geodata(?string $failing = null): HamburgGeodata
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($failing): MockResponse {
            $this->requested[] = $url;
            if (null !== $failing && str_contains($url, '/'.$failing.'/')) {
                return new MockResponse('', ['http_code' => 503]);
            }

            return new MockResponse((string) json_encode(match (true) {
                str_contains($url, '/'.HamburgBuildings::DATASET_ALKIS.'/') => $this->alkis(),
                str_contains($url, '/'.HamburgBuildings::DATASET_LOD2.'/') => $this->lod2(),
                str_contains($url, '/'.HamburgTrees::DATASET.'/') => $this->trees(),
                str_contains($url, '/'.HamburgGeodata::DATASET_INFILTRATION.'/') => $this->infiltration(),
                default => [],
            }));
        });
        $api = new HamburgApi($client, new ArrayAdapter(), 'test');

        return new HamburgGeodata($api, new HamburgBuildings($api), new HamburgTrees($api), new HamburgElevation($client, new ArrayAdapter(), 'test'), new NullLogger());
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

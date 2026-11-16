<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Geo;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\SurroundingsImporter;
use Core\Configurator\Site\Facade;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SurroundingsImporterTest extends TestCase
{
    private const float LATITUDE = 53.55;
    private const float LONGITUDE = 10.02;
    /** about 11 m north-south */
    private const float STEP = 0.0001;

    #[Test]
    public function importsTheNeighboursButNotTheSitesOwnBuildings(): void
    {
        $neighbour = $this->way(['building' => 'apartments', 'building:levels' => '5', 'building:material' => 'brick'], 20);
        $own = $this->way(['building' => 'school'], 0);
        $street = ['type' => 'way', 'tags' => ['highway' => 'residential'], 'geometry' => [
            ['lat' => self::LATITUDE, 'lon' => self::LONGITUDE], ['lat' => self::LATITUDE + 30 * self::STEP, 'lon' => self::LONGITUDE],
        ]];
        // the site holds the school (the square at the centre)
        $site = Site::fromJson((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::BUILDING, O::POINTS => [[-2000, -2000], [10000, -2000], [10000, 2000], [-2000, 2000]], O::HEIGHT => 9000],
        ]]));

        $surroundings = (new SurroundingsImporter())->import(new GeoPoint(self::LATITUDE, self::LONGITUDE, 'Test'), [$neighbour, $own, $street], $site, 350.0);

        self::assertCount(1, $surroundings->buildings);
        $building = $surroundings->buildings[0];
        self::assertSame(15.0, $building['height']);
        self::assertSame(Facade::Brick->value, $building['facade']);
        // plan metres: north is -z, about 222 m away
        self::assertEqualsWithDelta(-222.0, $building['points'][0][1], 1.0);
        self::assertCount(1, $surroundings->roads);
        self::assertSame(7.0, $surroundings->roads[0]['width']);
        self::assertSame(O::ROAD_CARRIAGEWAY, $surroundings->roads[0]['kind']);
    }

    /**
     * A closed square way about 7 m wide, $north steps north of the centre.
     *
     * @param array<string, string> $tags
     *
     * @return array<string, mixed>
     */
    private function way(array $tags, int $north): array
    {
        $lat = self::LATITUDE + $north * self::STEP;
        $ring = [[0, 0], [0, 1], [0.6, 1], [0.6, 0], [0, 0]];

        return ['type' => 'way', 'tags' => $tags, 'geometry' => array_map(
            static fn (array $p): array => ['lat' => $lat + $p[0] * self::STEP, 'lon' => self::LONGITUDE + $p[1] * self::STEP],
            $ring,
        )];
    }
}

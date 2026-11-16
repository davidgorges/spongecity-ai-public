<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Geo;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\Hamburg\CityGmlRoofs;
use App\SpongeCity\Geo\UtmPlanTransform;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteBuilder;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Roof levels from Hamburg's 3D building models, and how a building with them is drawn.
 */
final class CityGmlRoofsTest extends TestCase
{
    private const float LATITUDE = 53.5513228;
    private const float LONGITUDE = 10.0187849;

    #[Test]
    public function readsRoofLevelsAboveTheGround(): void
    {
        $transform = new UtmPlanTransform(new GeoPoint(self::LATITUDE, self::LONGITUDE));
        $gml = self::building($transform, 'DEHHTEST', [[0, 0, 20, 10, 20.8], [0, 10, 20, 14, 24.3], [20, 0, 30, 14, 6.6]]);

        // the same, fed in small pieces as a download arrives
        $streamed = CityGmlRoofs::streamed(static function (callable $consume) use ($gml): void {
            foreach (str_split($gml, 97) as $piece) {
                $consume($piece);
            }
        }, $transform, 100);
        $buildings = CityGmlRoofs::buildings($gml, $transform, 100);

        self::assertCount(1, $buildings);
        self::assertSame('DEHHTEST', $buildings[0]['id']);
        self::assertSame([20.8, 24.3, 6.6], array_map(static fn (array $s): float => round($s['high'], 1), $buildings[0]['surfaces']), 'heights above the ground at 3 m');
        self::assertEqualsWithDelta(200.0, CityGmlRoofs::area($buildings[0]['surfaces'][0]['points']), 0.5);
        self::assertEquals($buildings, $streamed);
        // seen from a centre 1 km away, the building is outside the square
        self::assertSame([], CityGmlRoofs::buildings($gml, new UtmPlanTransform(new GeoPoint(self::LATITUDE + 0.009, self::LONGITUDE)), 100));
    }

    #[Test]
    public function drawsABlockPerRoofLevel(): void
    {
        $site = Site::fromJson((new SiteType())->normalize(['objects' => [[
            O::TYPE => O::BUILDING, O::POINTS => [[0, 0], [30000, 0], [30000, 14000], [0, 14000]], O::HEIGHT => 24300, O::ROOF => 'flat', O::STOREYS => 5,
            O::PARTS => [
                [O::POINTS => [[0, 0], [20000, 0], [20000, 10000], [0, 10000]], O::HEIGHT => 20800],
                [O::POINTS => [[0, 10000], [20000, 10000], [20000, 14000], [0, 14000]], O::HEIGHT => 24300],
                [O::POINTS => [[20000, 0], [30000, 0], [30000, 14000], [20000, 14000]], O::HEIGHT => 6600],
                [O::POINTS => [[1, 2]], O::HEIGHT => 5000], // unreadable: left out
            ],
        ]]]));

        self::assertCount(3, $site->objects[0][O::PARTS]);
        $parts = (new SiteBuilder())->build($site)->parts();
        $walls = array_values(array_filter($parts, static fn ($p): bool => str_contains((string) $p->material, 'facade')));
        self::assertCount(6, $walls, 'a block per level (each a ground floor and the storeys above), not one at the highest point');
        // the storeys belong to the main (first) level: 20.8 m / 5 = 4.16 m, the ground floor a quarter taller
        $heights = array_map(static fn ($m): ?float => $m->textureHeight, array_values(array_filter((new SiteBuilder())->build($site)->materials(), static fn ($m): bool => null !== $m->textureHeight && str_contains($m->color, '#'))));
        self::assertEqualsWithDelta(4.16 * 1.25 * 4, $heights[0], 0.01);
        self::assertEqualsWithDelta((20.8 - 4.16 * 1.25) / 4 * 4, $heights[1], 0.01);
    }

    /**
     * A CityGML building with a ground at 3 m and flat roof surfaces (x0, z0, x1, z1 in plan metres, height above the ground).
     *
     * @param list<array{float, float, float, float, float}> $roofs
     */
    private static function building(UtmPlanTransform $transform, string $id, array $roofs): string
    {
        $ring = static function (float $x0, float $z0, float $x1, float $z1, float $height) use ($transform): string {
            $corners = [[$x0, $z0], [$x1, $z0], [$x1, $z1], [$x0, $z1], [$x0, $z0]];

            return '<gml:posList>'.implode(' ', array_map(static function (array $c) use ($transform, $height): string {
                [$e, $n] = $transform->toUtm($c[0], $c[1]);

                return sprintf('%.3f %.3f %.3f', $e, $n, $height);
            }, $corners)).'</gml:posList>';
        };
        $xml = "<core:cityObjectMember><bldg:Building gml:id=\"{$id}\">\n";
        $xml .= '<bldg:boundedBy><bldg:GroundSurface>'.$ring(0, 0, 30, 14, 3.0)."</bldg:GroundSurface></bldg:boundedBy>\n";
        foreach ($roofs as [$x0, $z0, $x1, $z1, $height]) {
            $xml .= '<bldg:boundedBy><bldg:RoofSurface>'.$ring($x0, $z0, $x1, $z1, 3.0 + $height)."</bldg:RoofSurface></bldg:boundedBy>\n";
        }

        return '<core:CityModel>'.$xml."</bldg:Building></core:cityObjectMember></core:CityModel>\n";
    }
}

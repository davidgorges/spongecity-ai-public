<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\UtmPlanTransform;

/**
 * Reads the roof surfaces of the buildings in a CityGML tile of Hamburg's 3D building
 * models (LoD2-DE and LoD3.0-HH: UTM 32 coordinates, heights above sea level), in plan
 * metres and as heights above each building's lowest ground point. What tells a roof's
 * levels apart: a low wing, the main roof, a recessed top storey, a stair core; and, with
 * every corner's height and the walls, what a pitched roof looks like (RoofFit).
 *
 * @phpstan-type Surface array{points: list<array{0: float, 1: float}>, heights: list<float>, low: float, high: float}
 * @phpstan-type Wall array{points: list<array{0: float, 1: float}>, heights: list<float>}
 * @phpstan-type Model array{id: string, centre: array{0: float, 1: float}, roofType: string, surfaces: list<Surface>, walls: list<Wall>}
 */
final class CityGmlRoofs
{
    private const string BUILDING_START = '<bldg:Building ';
    private const string BUILDING_END = '</bldg:Building>';
    private const string BUILDING_ID = '#^<bldg:Building gml:id="([^"]*)"#';
    private const string ROOF_TYPE = '#<bldg:roofType>([^<]*)</bldg:roofType>#';
    private const string ROOF_SURFACE = '#<bldg:RoofSurface.*?</bldg:RoofSurface>#s';
    private const string WALL_SURFACE = '#<bldg:WallSurface.*?</bldg:WallSurface>#s';
    private const string GROUND_SURFACE = '#<bldg:GroundSurface.*?</bldg:GroundSurface>#s';
    /** what a surface holds besides its own outline: holes, and (LoD3) windows and doors */
    private const string NOT_OUTLINE = '#<gml:interior>.*?</gml:interior>|<bldg:opening>.*?</bldg:opening>#s';
    private const string POSITIONS = '#<gml:posList[^>]*>([^<]*)</gml:posList>#';
    private const string FIRST_POSITION = '#<gml:pos(?:List)?[^>]*>\s*([\d.]+)\s+([\d.]+)#';
    private const int HEIGHT_DECIMALS = 3;

    private function __construct()
    {
    }

    /**
     * The same as buildings(), from a file arriving in pieces (RemoteZip::stream): each building
     * is read as soon as its closing tag has arrived, so the file is never in memory at once.
     *
     * @param callable(callable(string): void): void $source feeds the pieces to the callback it is given
     *
     * @return list<Model>
     */
    public static function streamed(callable $source, UtmPlanTransform $transform, float $halfSideMetres, bool $walls = false): array
    {
        $buildings = [];
        $buffer = '';
        $source(static function (string $piece) use (&$buffer, &$buildings, $transform, $halfSideMetres, $walls): void {
            $buffer .= $piece;
            $end = strrpos($buffer, self::BUILDING_END);
            if (false === $end) {
                return;
            }
            $complete = $end + strlen(self::BUILDING_END);
            array_push($buildings, ...self::buildings(substr($buffer, 0, $complete), $transform, $halfSideMetres, $walls));
            $buffer = substr($buffer, $complete);
        });

        return $buildings;
    }

    /**
     * The buildings standing (by their first vertex) within a square of the plan.
     *
     * @param bool $walls with the wall surfaces (empty otherwise: only the drawing of a whole model needs them)
     *
     * @return list<Model>
     */
    public static function buildings(string $cityGml, UtmPlanTransform $transform, float $halfSideMetres, bool $walls = false): array
    {
        $buildings = [];
        // one building at a time: a tile of the detailed model is tens of megabytes
        for ($offset = 0; false !== ($start = strpos($cityGml, self::BUILDING_START, $offset));) {
            $end = strpos($cityGml, self::BUILDING_END, $start);
            if (false === $end) {
                break;
            }
            $member = substr($cityGml, $start, $end - $start + strlen(self::BUILDING_END));
            $offset = $end;
            $id = 1 === preg_match(self::BUILDING_ID, $member, $match) ? $match[1] : '';
            if (1 !== preg_match(self::FIRST_POSITION, $member, $first)) {
                continue;
            }
            [$x, $z] = $transform->toPlan((float) $first[1], (float) $first[2]);
            if (abs($x) > $halfSideMetres || abs($z) > $halfSideMetres) {
                continue;
            }
            $ground = self::lowest($member);
            if (null === $ground) {
                continue;
            }
            $surfaces = [];
            foreach (self::rings($member, self::ROOF_SURFACE, $transform, $ground) as $ring) {
                $surfaces[] = [...$ring, 'low' => min($ring['heights']), 'high' => max($ring['heights'])];
            }
            if ([] === $surfaces) {
                continue;
            }
            $all = array_merge(...array_column($surfaces, 'points'));
            $buildings[] = [
                'id' => $id,
                'centre' => [array_sum(array_column($all, 0)) / count($all), array_sum(array_column($all, 1)) / count($all)],
                'roofType' => 1 === preg_match(self::ROOF_TYPE, $member, $type) ? trim($type[1]) : '',
                'surfaces' => $surfaces,
                'walls' => $walls ? self::rings($member, self::WALL_SURFACE, $transform, $ground) : [],
            ];
        }

        return $buildings;
    }

    /**
     * @param list<array{0: float, 1: float}> $points
     */
    public static function area(array $points): float
    {
        $sum = 0.0;
        for ($i = 0, $n = count($points); $i < $n; ++$i) {
            $sum += $points[$i][0] * $points[($i + 1) % $n][1] - $points[($i + 1) % $n][0] * $points[$i][1];
        }

        return abs($sum) / 2;
    }

    /**
     * The outer rings of a kind of surface: plan points and heights above the ground.
     *
     * @return list<Wall>
     */
    private static function rings(string $member, string $surface, UtmPlanTransform $transform, float $ground): array
    {
        $rings = [];
        preg_match_all($surface, $member, $found);
        foreach ($found[0] as $xml) {
            preg_match_all(self::POSITIONS, (string) preg_replace(self::NOT_OUTLINE, '', $xml), $lists);
            foreach ($lists[1] as $list) {
                $coordinates = self::coordinates($list);
                if (count($coordinates) < 3) {
                    continue;
                }
                $rings[] = [
                    'points' => array_map(static fn (array $c): array => $transform->toPlan($c[0], $c[1]), $coordinates),
                    'heights' => array_map(static fn (array $c): float => round($c[2] - $ground, self::HEIGHT_DECIMALS), $coordinates),
                ];
            }
        }

        return $rings;
    }

    private static function lowest(string $member): ?float
    {
        preg_match_all(self::GROUND_SURFACE, $member, $surfaces);
        $zs = [];
        foreach ($surfaces[0] as $surface) {
            preg_match_all(self::POSITIONS, $surface, $lists);
            foreach ($lists[1] as $list) {
                array_push($zs, ...array_column(self::coordinates($list), 2));
            }
        }

        return [] === $zs ? null : min($zs);
    }

    /**
     * @return list<array{0: float, 1: float, 2: float}> without the closing point
     */
    private static function coordinates(string $list): array
    {
        $values = array_map('floatval', preg_split('/\s+/', trim($list)) ?: []);
        $points = [];
        for ($i = 0; $i + 2 < count($values); $i += 3) {
            $points[] = [$values[$i], $values[$i + 1], $values[$i + 2]];
        }
        if (count($points) > 1 && $points[0] === end($points)) {
            array_pop($points);
        }

        return $points;
    }
}

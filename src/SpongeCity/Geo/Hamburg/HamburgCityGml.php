<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\RemoteZip;
use App\SpongeCity\Geo\UtmPlanTransform;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The buildings around a site as CityGML, cut out of Hamburg's 3D building model
 * (LoD2-DE, CityGML 1.0 in the AdV profile, 1 × 1 km tiles in a 660 MB ZIP read with
 * range requests): the input of SimStadt's building simulations.
 *
 * Two things SimStadt needs are added: the envelope with its reference system (it
 * locates the model and its weather by it), and a construction year, which Hamburg's
 * model does not have (SimStadt leaves buildings without one out). The year given is
 * an assumption; it decides the building physics, so it is reported with every result.
 */
final readonly class HamburgCityGml
{
    public const string ZIP_URL = 'https://daten-hamburg.de/opendata/3d_stadtmodell_lod2/LoD2-DE_HH_2026-04-28.zip';
    public const string AS_OF = '2026-04-28';
    /** tiles by the kilometre of their south-west corner: LoD2_32_<E km>_<N km>_1_HH.gml */
    private const string TILE_PATTERN = '#^LoD2_32_%d_%d_1_HH\.gml$#';
    private const int TILE_METRES = 1000;
    private const int CACHE_SECONDS = 604_800;
    private const string SRS = 'urn:adv:crs:ETRS89_UTM32*DE_DHHN2016_NH';
    private const string MEMBER = '#<core:cityObjectMember>.*?</core:cityObjectMember>#s';
    private const string FIRST_POSITION = '#<gml:pos(?:List)?[^>]*>\s*([\d.]+)\s+([\d.]+)#';
    private const string POSITIONS = '#<gml:posList[^>]*>([^<]*)</gml:posList>#';
    private const string BUILDING_ID = '#<bldg:Building gml:id="([^"]+)"#';

    private RemoteZip $zip;

    public function __construct(
        HttpClientInterface $httpClient,
        CacheInterface $cache,
        #[Autowire(env: 'OSM_USER_AGENT')]
        string $userAgent,
    ) {
        $this->zip = new RemoteZip($httpClient, $cache, $userAgent, self::ZIP_URL, self::CACHE_SECONDS);
    }

    /**
     * The buildings that stand (by their first vertex) within a square of the plan.
     *
     * @return array{gml: string, buildings: list<string>} the CityGML and the ids of its buildings
     */
    public function extract(GeoPoint $centre, float $halfSideMetres, int $yearOfConstruction): array
    {
        $transform = new UtmPlanTransform($centre);
        $header = null;
        $members = [];
        $ids = [];
        foreach ($this->tileNames($transform, $halfSideMetres) as $name) {
            $tile = $this->zip->read($name);
            $header ??= self::header($tile);
            preg_match_all(self::MEMBER, $tile, $found);
            foreach ($found[0] as $member) {
                if (1 !== preg_match(self::FIRST_POSITION, $member, $position)) {
                    continue;
                }
                [$x, $z] = $transform->toPlan((float) $position[1], (float) $position[2]);
                if (abs($x) <= $halfSideMetres && abs($z) <= $halfSideMetres) {
                    $members[] = self::withYear($member, $yearOfConstruction);
                    if (1 === preg_match(self::BUILDING_ID, $member, $id)) {
                        $ids[] = $id[1];
                    }
                }
            }
        }
        if (null === $header || [] === $members) {
            return ['gml' => '', 'buildings' => []];
        }

        return ['gml' => $header.self::envelope($members).implode("\n", $members)."\n</core:CityModel>\n", 'buildings' => $ids];
    }

    /**
     * The roof surfaces of the model's buildings within a square of the plan (see CityGmlRoofs).
     *
     * @return list<array{id: string, centre: array{0: float, 1: float}, surfaces: list<array{points: list<array{0: float, 1: float}>, low: float, high: float}>}>
     */
    public function roofSurfaces(GeoPoint $centre, float $halfSideMetres): array
    {
        $transform = new UtmPlanTransform($centre);
        $buildings = [];
        foreach ($this->tileNames($transform, $halfSideMetres) as $name) {
            array_push($buildings, ...CityGmlRoofs::buildings($this->zip->read($name), $transform, $halfSideMetres));
        }

        return $buildings;
    }

    /**
     * The model's tiles covering a square of the plan.
     *
     * @return list<string>
     */
    private function tileNames(UtmPlanTransform $transform, float $halfSideMetres): array
    {
        $corners = array_map(static fn (array $c): array => $transform->toUtm($c[0] * $halfSideMetres, $c[1] * $halfSideMetres), [[-1, -1], [1, -1], [1, 1], [-1, 1]]);
        $names = [];
        for ($e = (int) floor(min(array_column($corners, 0)) / self::TILE_METRES); $e <= (int) floor(max(array_column($corners, 0)) / self::TILE_METRES); ++$e) {
            for ($n = (int) floor(min(array_column($corners, 1)) / self::TILE_METRES); $n <= (int) floor(max(array_column($corners, 1)) / self::TILE_METRES); ++$n) {
                $name = $this->zip->find(sprintf(self::TILE_PATTERN, $e, $n));
                if (null !== $name) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /** The tile's opening (namespaces, name) without its own envelope. */
    private static function header(string $tile): string
    {
        $start = strpos($tile, '<core:cityObjectMember>');
        $header = false === $start ? '' : substr($tile, 0, $start);

        return (string) preg_replace('#\s*<gml:boundedBy>.*?</gml:boundedBy>#s', '', $header)."\n";
    }

    /**
     * @param list<string> $members
     */
    private static function envelope(array $members): string
    {
        [$low, $high] = [[INF, INF, INF], [-INF, -INF, -INF]];
        foreach ($members as $member) {
            preg_match_all(self::POSITIONS, $member, $lists);
            foreach ($lists[1] as $list) {
                $values = preg_split('/\s+/', trim($list)) ?: [];
                for ($i = 0; $i + 2 < count($values); $i += 3) {
                    for ($k = 0; $k < 3; ++$k) {
                        $low[$k] = min($low[$k], (float) $values[$i + $k]);
                        $high[$k] = max($high[$k], (float) $values[$i + $k]);
                    }
                }
            }
        }

        return sprintf(
            "  <gml:boundedBy>\n    <gml:Envelope srsName=\"%s\" srsDimension=\"3\">\n      <gml:lowerCorner srsDimension=\"3\">%.3f %.3f %.3f</gml:lowerCorner>\n      <gml:upperCorner srsDimension=\"3\">%.3f %.3f %.3f</gml:upperCorner>\n    </gml:Envelope>\n  </gml:boundedBy>\n",
            self::SRS, ...$low, ...$high,
        );
    }

    /** a construction year before the roof type, where CityGML 1.0 orders it (buildings and their parts) */
    private static function withYear(string $member, int $year): string
    {
        if (str_contains($member, '<bldg:yearOfConstruction>')) {
            return $member;
        }

        return (string) preg_replace('#(\s*)<bldg:roofType#', '$1<bldg:yearOfConstruction>'.$year.'</bldg:yearOfConstruction>$1<bldg:roofType', $member);
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\GeodataCache;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\RemoteZip;
use App\SpongeCity\Geo\UtmPlanTransform;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hamburg's detailed building model LoD3.0-HH (LGV, dl-de/by-2-0): the roofscape modelled
 * by hand from the 2020 vertical and oblique aerial photos, with recessed top storeys,
 * steps, overhangs and roof structures from one square metre. Published in five areas, each
 * a ZIP of 1 × 1 km CityGML tiles named by the last two digits of their UTM kilometre
 * (6734.gml: 567 km east, 5934 km north); a tile can be split over two areas. Read by range
 * requests (RemoteZip), only the tiles a site needs.
 *
 * Its building ids are mostly the cadastre's, but not always (some areas use their own),
 * so buildings are matched by position as well.
 */
final readonly class HamburgLod3
{
    /** the untextured model, areas 1 to 5 */
    public const array ZIP_URLS = [
        'https://daten-hamburg.de/opendata/3d_stadtmodell_lod3/LoD3-HH_Area1_noTexture_2025_03_13.zip',
        'https://daten-hamburg.de/opendata/3d_stadtmodell_lod3/LoD3-HH_Area2_noTexture_2025_03_19.zip',
        'https://daten-hamburg.de/opendata/3d_stadtmodell_lod3/LoD3-HH_Area3_noTexture_2025_04_28.zip',
        'https://daten-hamburg.de/opendata/3d_stadtmodell_lod3/LoD3-HH_Area4_noTexture_2025_04_28.zip',
        'https://daten-hamburg.de/opendata/3d_stadtmodell_lod3/LoD3-HH_Area5_noTexture_2025_04_28.zip',
    ];
    public const string AS_OF = '2020 (published 2025)';
    private const string TILE_PATTERN = '#(^|/)%02d%02d\.gml$#';
    private const int TILE_METRES = 1000;
    private const int KILOMETRES_PER_NAME = 100;
    /** 3: corner heights, roof types and walls, holes and openings left out */
    private const int CACHE_VERSION = 3;
    private const int COORDINATE_DECIMALS = 5;

    /** @var list<RemoteZip> */
    private array $zips;

    public function __construct(
        HttpClientInterface $httpClient,
        #[Autowire(service: GeodataCache::POOL)]
        private CacheInterface $cache,
        private LoggerInterface $logger,
        /* the application cache on disk: the zip archives are too large for Redis */
        CacheInterface $downloads,
    ) {
        $this->zips = array_map(static fn (string $url): RemoteZip => new RemoteZip($httpClient, $downloads, $url, GeodataCache::LIFETIME_SECONDS), self::ZIP_URLS);
    }

    /**
     * The roof and wall surfaces of the model's buildings within a square of the plan; none when
     * the model cannot be read.
     *
     * @return list<array{id: string, centre: array{0: float, 1: float}, roofType: string, surfaces: list<array{points: list<array{0: float, 1: float}>, heights: list<float>, low: float, high: float}>, walls: list<array{points: list<array{0: float, 1: float}>, heights: list<float>}>}>
     */
    public function roofSurfaces(GeoPoint $centre, float $halfSideMetres): array
    {
        // the tiles are too large to cache as they are: what the site needs of them is
        $key = sprintf('lod3_roofs_v%d_%s_%s_%d', self::CACHE_VERSION, round($centre->latitude, self::COORDINATE_DECIMALS), round($centre->longitude, self::COORDINATE_DECIMALS), (int) $halfSideMetres);

        return $this->cache->get($key, function (ItemInterface $item) use ($centre, $halfSideMetres): array {
            $item->expiresAfter(GeodataCache::LIFETIME_SECONDS);

            return $this->read($centre, $halfSideMetres);
        });
    }

    /**
     * @return list<array{id: string, centre: array{0: float, 1: float}, roofType: string, surfaces: list<array{points: list<array{0: float, 1: float}>, heights: list<float>, low: float, high: float}>, walls: list<array{points: list<array{0: float, 1: float}>, heights: list<float>}>}>
     */
    private function read(GeoPoint $centre, float $halfSideMetres): array
    {
        $transform = new UtmPlanTransform($centre);
        $corners = array_map(static fn (array $c): array => $transform->toUtm($c[0] * $halfSideMetres, $c[1] * $halfSideMetres), [[-1, -1], [1, -1], [1, 1], [-1, 1]]);
        $buildings = [];
        try {
            for ($e = (int) floor(min(array_column($corners, 0)) / self::TILE_METRES); $e <= (int) floor(max(array_column($corners, 0)) / self::TILE_METRES); ++$e) {
                for ($n = (int) floor(min(array_column($corners, 1)) / self::TILE_METRES); $n <= (int) floor(max(array_column($corners, 1)) / self::TILE_METRES); ++$n) {
                    $pattern = sprintf(self::TILE_PATTERN, $e % self::KILOMETRES_PER_NAME, $n % self::KILOMETRES_PER_NAME);
                    foreach ($this->zips as $zip) {
                        $name = $zip->find($pattern);
                        if (null !== $name) {
                            array_push($buildings, ...CityGmlRoofs::streamed(static fn (callable $consume) => $zip->stream($name, $consume), $transform, $halfSideMetres, walls: true));
                        }
                    }
                }
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('LoD3 building model unavailable, roof levels from LoD2', ['exception' => $exception]);

            return [];
        }

        return $buildings;
    }
}

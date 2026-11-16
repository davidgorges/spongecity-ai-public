<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\GeoBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Hamburg's open geodata through its OGC API Features (api.hamburg.de, ldproxy): the
 * features of a collection within a bounding box, as GeoJSON (or, for the LoD2 building
 * model, CityJSON). Answers are cached per box for a day; the city's data changes yearly.
 */
final readonly class HamburgApi
{
    public const string BASE_URL = 'https://api.hamburg.de/datasets/v1';
    public const string FORMAT_JSON = 'json';
    public const string FORMAT_CITYJSON = 'cityjson';

    /** ldproxy accepts large pages; a 700 m square of the inner city holds about 500 buildings */
    private const int PAGE_SIZE = 5000;
    private const int MAX_PAGES = 4;
    /** the ALKIS service takes about 20 s for a few hundred buildings */
    private const int REQUEST_TIMEOUT_SECONDS = 30;
    private const int MAX_DURATION_SECONDS = 60;
    private const int CACHE_SECONDS = 86400;
    /** bump when a query changes, so answers to the old one are not reused */
    private const int CACHE_VERSION = 1;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire(env: 'OSM_USER_AGENT')]
        private string $userAgent,
    ) {
    }

    /**
     * @return list<array<string, mixed>> GeoJSON features (CRS84: [longitude, latitude])
     */
    public function features(string $dataset, string $collection, GeoBox $box): array
    {
        /** @var list<array<string, mixed>> */
        return $this->cached($dataset, $collection, $box, self::FORMAT_JSON, static function (array $page): array {
            return array_values(array_filter(is_array($page['features'] ?? null) ? $page['features'] : [], 'is_array'));
        });
    }

    /**
     * The CityJSON city objects of a collection (their attributes; the geometry's vertices
     * come back quantised to whole units of the CRS, so it is not used for footprints).
     *
     * @return array<string, array<string, mixed>> id → city object, each with its "vertices" resolved to [x, y, z]
     */
    public function cityObjects(string $dataset, string $collection, GeoBox $box): array
    {
        $objects = [];
        foreach ($this->cached($dataset, $collection, $box, self::FORMAT_CITYJSON, static function (array $page): array {
            $vertices = is_array($page['vertices'] ?? null) ? $page['vertices'] : [];
            $scale = is_array($page['transform']['scale'] ?? null) ? $page['transform']['scale'] : [1, 1, 1];
            $translate = is_array($page['transform']['translate'] ?? null) ? $page['transform']['translate'] : [0, 0, 0];
            $objects = [];
            foreach (is_array($page['CityObjects'] ?? null) ? $page['CityObjects'] : [] as $id => $object) {
                if (is_array($object)) {
                    $object['zRange'] = self::zRanges($object, $vertices, (float) $scale[2], (float) $translate[2]);
                    unset($object['geometry']);
                    $objects[] = ['id' => (string) $id, ...$object];
                }
            }

            return $objects;
        }) as $object) {
            $objects[(string) $object['id']] = $object;
        }

        return $objects;
    }

    /**
     * Lowest and highest point of each kind of surface (GroundSurface, RoofSurface, …).
     *
     * @param array<string, mixed>  $object
     * @param list<list<int|float>> $vertices
     *
     * @return array<string, array{float, float}>
     */
    private static function zRanges(array $object, array $vertices, float $scale, float $translate): array
    {
        $ranges = [];
        foreach (is_array($object['geometry'] ?? null) ? $object['geometry'] : [] as $geometry) {
            $surfaces = $geometry['semantics']['surfaces'] ?? [];
            $values = $geometry['semantics']['values'][0] ?? [];
            foreach ($geometry['boundaries'][0] ?? [] as $i => $surface) {
                $type = $surfaces[$values[$i] ?? -1]['type'] ?? null;
                if (!is_string($type)) {
                    continue;
                }
                array_walk_recursive($surface, static function (mixed $index) use (&$ranges, $type, $vertices, $scale, $translate): void {
                    $z = isset($vertices[$index][2]) ? $vertices[$index][2] * $scale + $translate : null;
                    if (null !== $z) {
                        $ranges[$type] = [min($ranges[$type][0] ?? $z, $z), max($ranges[$type][1] ?? $z, $z)];
                    }
                });
            }
        }

        return $ranges;
    }

    /**
     * @param callable(array<string, mixed>): list<array<string, mixed>> $extract one page → its items
     *
     * @return list<array<string, mixed>>
     */
    private function cached(string $dataset, string $collection, GeoBox $box, string $format, callable $extract): array
    {
        $key = sprintf('hamburg_v%d_%s', self::CACHE_VERSION, hash('xxh128', implode('|', [$dataset, $collection, $format, $box->toBbox()])));

        return $this->cache->get($key, function (ItemInterface $item) use ($dataset, $collection, $box, $format, $extract): array {
            $item->expiresAfter(self::CACHE_SECONDS);
            $items = [];
            for ($page = 0; $page < self::MAX_PAGES; ++$page) {
                $data = $this->httpClient->request('GET', sprintf('%s/%s/collections/%s/items', self::BASE_URL, $dataset, $collection), [
                    'query' => ['f' => $format, 'bbox' => $box->toBbox(), 'limit' => self::PAGE_SIZE, 'offset' => $page * self::PAGE_SIZE],
                    'headers' => ['User-Agent' => $this->userAgent],
                    'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                    'max_duration' => self::MAX_DURATION_SECONDS,
                ])->toArray();
                $found = $extract($data);
                array_push($items, ...$found);
                if (count($found) < self::PAGE_SIZE) {
                    break;
                }
            }

            return $items;
        });
    }
}

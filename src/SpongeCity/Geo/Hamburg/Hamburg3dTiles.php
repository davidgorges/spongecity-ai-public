<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\GeoBox;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The feature attributes of Hamburg's 3D Tiles layers (Cesium 3D Tiles 1.0 as the city's
 * 3D portal serves them) within a box: the tileset is walked down to the leaf tiles
 * touching the box (following external tilesets), and every leaf's batch table is read
 * (b3dm, i3dm, or a composite of them). Only attributes; the geometry is not decoded.
 * Tiles are cached for a week.
 */
final readonly class Hamburg3dTiles
{
    public const string BASE_URL = 'https://www.daten-hamburg.de/gdi3d/datasource-data/';
    private const int CACHE_SECONDS = 604_800;
    private const int CACHE_VERSION = 1;
    private const int TIMEOUT_SECONDS = 30;
    /** tilesets nest a few levels; this guards against a cycle */
    private const int MAX_DEPTH = 12;

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
        #[Autowire(env: 'OSM_USER_AGENT')]
        private string $userAgent,
    ) {
    }

    /**
     * @return list<array<string, mixed>> one attribute set per feature
     */
    public function attributes(string $layer, GeoBox $box): array
    {
        $url = self::BASE_URL.$layer.'/tileset.json';
        $leaves = [];
        $this->walk($url, $this->json($url)['root'] ?? [], self::radians($box), $leaves, 0);
        $features = [];
        foreach (array_unique($leaves) as $leaf) {
            array_push($features, ...self::batchTables($this->fetch($leaf)));
        }

        return $features;
    }

    /**
     * @param array<string, mixed>       $node
     * @param array{float, float, float, float} $box west, south, east, north in radians
     * @param list<string>               $leaves
     */
    private function walk(string $base, array $node, array $box, array &$leaves, int $depth): void
    {
        if ($depth > self::MAX_DEPTH || !self::touches($node, $box)) {
            return;
        }
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $uri = $node['content']['uri'] ?? $node['content']['url'] ?? null;
        if (is_string($uri)) {
            $url = self::resolve($base, $uri);
            if (str_ends_with(strtolower(parse_url($url, \PHP_URL_PATH) ?: ''), '.json')) {
                // an external tileset: its root continues the tree
                $this->walk($url, $this->json($url)['root'] ?? [], $box, $leaves, $depth + 1);
            } elseif ([] === $children) {
                // refinement replaces a parent by its children: only the leaves hold the finest features
                $leaves[] = $url;
            }
        }
        foreach ($children as $child) {
            if (is_array($child)) {
                $this->walk($base, $child, $box, $leaves, $depth + 1);
            }
        }
    }

    /**
     * @param array<string, mixed>              $node
     * @param array{float, float, float, float} $box
     */
    private static function touches(array $node, array $box): bool
    {
        $region = $node['boundingVolume']['region'] ?? null;
        if (!is_array($region) || count($region) < 4) {
            return true; // no region: cannot rule it out
        }

        return $region[0] <= $box[2] && $region[2] >= $box[0] && $region[1] <= $box[3] && $region[3] >= $box[1];
    }

    /**
     * The batch tables of a tile (or of the tiles in a composite) as one attribute set per feature.
     *
     * @return list<array<string, mixed>>
     */
    public static function batchTables(string $tile): array
    {
        $magic = substr($tile, 0, 4);
        if ('cmpt' === $magic) {
            $features = [];
            $count = unpack('V', $tile, 12)[1];
            for ($i = 0, $p = 16; $i < $count && $p + 12 <= strlen($tile); ++$i) {
                $length = unpack('V', $tile, $p + 8)[1];
                array_push($features, ...self::batchTables(substr($tile, $p, $length)));
                $p += $length;
            }

            return $features;
        }
        $header = match ($magic) {
            'b3dm' => 28,
            'i3dm' => 32,
            default => null,
        };
        if (null === $header || strlen($tile) < $header) {
            return [];
        }
        [$featureJson, $featureBinary, $batchJson] = array_values(unpack('V3', $tile, 12));
        $table = json_decode(substr($tile, $header + $featureJson + $featureBinary, $batchJson), true);
        if (!is_array($table)) {
            return [];
        }
        // the city's tiles keep one object per feature under "attributes"; plain 3D Tiles keep a column per property
        if (is_array($table['attributes'] ?? null) && array_is_list($table['attributes'])) {
            return array_values(array_filter($table['attributes'], 'is_array'));
        }
        $columns = array_filter($table, static fn (mixed $column): bool => is_array($column) && array_is_list($column));
        $count = max(array_map('count', $columns) ?: [0]);
        $features = [];
        for ($i = 0; $i < $count; ++$i) {
            $features[] = array_map(static fn (array $column): mixed => $column[$i] ?? null, $columns);
        }

        return $features;
    }

    /** a relative URI against the URL of the tileset that holds it (with ../ segments) */
    private static function resolve(string $base, string $uri): string
    {
        if (1 === preg_match('#^https?://#', $uri)) {
            return $uri;
        }
        $parts = parse_url($base);
        $path = explode('/', (string) ($parts['path'] ?? '/'));
        array_pop($path);
        foreach (explode('/', $uri) as $segment) {
            if ('..' === $segment) {
                array_pop($path);
            } elseif ('.' !== $segment && '' !== $segment) {
                $path[] = $segment;
            }
        }

        return sprintf('%s://%s%s', $parts['scheme'] ?? 'https', $parts['host'] ?? '', implode('/', $path));
    }

    /**
     * @return array{float, float, float, float}
     */
    private static function radians(GeoBox $box): array
    {
        return [deg2rad($box->west), deg2rad($box->south), deg2rad($box->east), deg2rad($box->north)];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $url): array
    {
        $data = json_decode($this->fetch($url), true);

        return is_array($data) ? $data : [];
    }

    private function fetch(string $url): string
    {
        return $this->cache->get(sprintf('tiles3d_v%d_%s', self::CACHE_VERSION, hash('xxh128', $url)), function (ItemInterface $item) use ($url): string {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->httpClient->request('GET', $url, [
                'headers' => ['User-Agent' => $this->userAgent],
                'timeout' => self::TIMEOUT_SECONDS,
            ])->getContent();
        });
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds places by name with OpenStreetMap's Nominatim. Answers are cached: the same address is
 * asked again and again (the home page's example schools), and Nominatim's usage policy asks
 * clients to cache. Failures and empty answers are not cached, so a place can be found later.
 */
final readonly class NominatimGeocoder
{
    public const string ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const int LIMIT = 5;
    private const int CACHE_SECONDS = 30 * 86400;
    /** bump when the query or the result shape changes */
    private const int CACHE_VERSION = 1;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        #[Autowire(service: GeodataCache::POOL)]
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return list<GeoPoint>
     */
    public function search(string $query): array
    {
        $key = sprintf('nominatim_v%d_%s', self::CACHE_VERSION, hash('xxh128', mb_strtolower(trim($query))));

        /** @var list<array{lat: string, lon: string, display_name: string}> $rows */
        $rows = $this->cache->get($key, function (ItemInterface $item, bool &$save) use ($query): array {
            $item->expiresAfter(self::CACHE_SECONDS);
            $rows = $this->fetch($query);
            $save = [] !== $rows;

            return $rows;
        });

        return array_map(static fn (array $row): GeoPoint => new GeoPoint((float) $row['lat'], (float) $row['lon'], $row['display_name']), $rows);
    }

    /**
     * @return list<array{lat: string, lon: string, display_name: string}> empty when nothing was found or the request failed
     */
    private function fetch(string $query): array
    {
        $response = $this->httpClient->request('GET', self::ENDPOINT, [
            'query' => ['q' => $query, 'format' => 'jsonv2', 'limit' => self::LIMIT],
        ]);

        try {
            /** @var list<array{lat: string, lon: string, display_name: string}> $rows */
            $rows = $response->toArray();
        } catch (\Throwable $exception) {
            $this->logger->warning('Nominatim search failed', ['query' => $query, 'exception' => $exception]);

            return [];
        }

        return array_map(static fn (array $row): array => ['lat' => $row['lat'], 'lon' => $row['lon'], 'display_name' => $row['display_name']], $rows);
    }
}

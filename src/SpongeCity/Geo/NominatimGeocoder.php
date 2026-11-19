<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Finds places by name with OpenStreetMap's Nominatim.
 */
final readonly class NominatimGeocoder
{
    public const string ENDPOINT = 'https://nominatim.openstreetmap.org/search';
    private const int LIMIT = 5;

    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<GeoPoint>
     */
    public function search(string $query): array
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

        return array_map(static fn (array $row): GeoPoint => new GeoPoint((float) $row['lat'], (float) $row['lon'], $row['display_name']), $rows);
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads buildings, trees and the area the point lies in from OpenStreetMap through the Overpass API.
 */
final readonly class OverpassClient
{
    /** public instances, tried in turn: each one is regularly overloaded, the first answering wins */
    public const array ENDPOINTS = [
        'https://overpass.openstreetmap.fr/api/interpreter',
        'https://overpass-api.de/api/interpreter',
        'https://lz4.overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];
    private const int QUERY_TIMEOUT_SECONDS = 25;
    /**
     * a mirror that does not start answering within this is skipped. Generous: under load the public
     * mirrors take 20 s even for small queries, and the import runs in a worker, so waiting costs nothing.
     */
    private const int REQUEST_TIMEOUT_SECONDS = 30;

    private const int CACHE_SECONDS = 86400;
    /** bump when the query changes, so answers to the old query are not reused */
    private const int CACHE_VERSION = 5;
    private const int COORDINATE_DECIMALS = 4;
    /** the streets and paths imported (highway=*) */
    private const string HIGHWAYS = 'primary|secondary|tertiary|unclassified|residential|living_street|service|pedestrian|footway|path|cycleway';

    public function __construct(
        private HttpClientInterface $httpClient,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @return list<array<string, mixed>> the raw Overpass elements (ways with geometry, nodes)
     */
    public function around(GeoPoint $centre, int $radiusMetres): array
    {
        // the public instances are shared and often overloaded: ask each place once a day
        $key = sprintf('overpass_v%d_%s_%s_%d', self::CACHE_VERSION, round($centre->latitude, self::COORDINATE_DECIMALS), round($centre->longitude, self::COORDINATE_DECIMALS), $radiusMetres);

        return $this->cache->get($key, function (ItemInterface $item) use ($centre, $radiusMetres): array {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->fetch($centre, $radiusMetres);
        });
    }

    /**
     * The buildings and streets around a site, out to $radiusMetres: its real surroundings.
     *
     * @return list<array<string, mixed>> the raw Overpass elements (ways with geometry)
     */
    public function surroundings(GeoPoint $centre, int $radiusMetres): array
    {
        $key = sprintf('overpass_surroundings_v%d_%s_%s_%d', self::CACHE_VERSION, round($centre->latitude, self::COORDINATE_DECIMALS), round($centre->longitude, self::COORDINATE_DECIMALS), $radiusMetres);

        return $this->cache->get($key, function (ItemInterface $item) use ($centre, $radiusMetres): array {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->request(sprintf(
                '[out:json][timeout:%1$d];('
                .'way(around:%4$d,%2$F,%3$F)["building"];'
                .'way(around:%4$d,%2$F,%3$F)["highway"~"^(%5$s)$"];'
                .');out geom;',
                self::QUERY_TIMEOUT_SECONDS,
                $centre->latitude,
                $centre->longitude,
                $radiusMetres,
                self::HIGHWAYS,
            ));
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetch(GeoPoint $centre, int $radiusMetres): array
    {
        // the areas the point lies in: is_in + pivot. ("around" measures the distance to a way's
        // outline, so a point well inside a school yard is not "around" its polygon.)
        $query = sprintf(
            '[out:json][timeout:%1$d];is_in(%2$F,%3$F)->.containing;('
            .'way(pivot.containing)["leisure"~"^(playground|pitch|garden|park|schoolyard)$"];'
            .'way(pivot.containing)["amenity"~"^(school|kindergarten|college|university)$"];'
            .'way(pivot.containing)["landuse"~"^(education|residential)$"];'
            .'way(around:%4$d,%2$F,%3$F)["building"];'
            .'way(around:%4$d,%2$F,%3$F)["highway"~"^(%5$s)$"];'
            .'node(around:%4$d,%2$F,%3$F)["natural"="tree"];'
            // play equipment (a swing, a slide…): its fall zone keeps every intervention off
            .'node(around:%4$d,%2$F,%3$F)["playground"];'
            // the ground areas on the plot: what they are used for decides what they may become
            .'way(around:%4$d,%2$F,%3$F)["amenity"~"^(parking|bicycle_parking)$"];'
            .'way(around:%4$d,%2$F,%3$F)["leisure"~"^(pitch|playground|track|garden)$"];'
            .'way(around:%4$d,%2$F,%3$F)["area:highway"];'
            .'way(around:%4$d,%2$F,%3$F)["landuse"~"^(grass|flowerbed|village_green)$"];'
            .'way(around:%4$d,%2$F,%3$F)["natural"~"^(scrub|grassland|heath)$"];'
            .');out geom;',
            self::QUERY_TIMEOUT_SECONDS,
            $centre->latitude,
            $centre->longitude,
            $radiusMetres,
            self::HIGHWAYS,
        );

        return $this->request($query);
    }

    /**
     * Asks the public instances in turn; the first that answers wins.
     *
     * @return list<array<string, mixed>>
     */
    private function request(string $query): array
    {
        $failure = null;
        foreach (self::ENDPOINTS as $endpoint) {
            try {
                $response = $this->httpClient->request('POST', $endpoint, [
                    'body' => ['data' => $query],
                    'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                    'max_duration' => self::QUERY_TIMEOUT_SECONDS + self::REQUEST_TIMEOUT_SECONDS,
                ]);
                /** @var array{elements?: list<array<string, mixed>>} $data */
                $data = $response->toArray();

                return $data['elements'] ?? [];
            } catch (ExceptionInterface $exception) {
                $failure = $exception;
            }
        }

        throw $failure ?? new \RuntimeException('No Overpass endpoint configured.');
    }
}

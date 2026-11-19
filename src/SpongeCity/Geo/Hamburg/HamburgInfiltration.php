<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Conditions\InfiltrationPotential;
use App\SpongeCity\Conditions\SiteConditions;
use App\SpongeCity\Geo\DataSource;
use App\SpongeCity\Geo\GeoBox;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\LocalProjection;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What Hamburg's infiltration potential map (Versickerungspotentialkarte) says about the
 * ground around a site, in plan metres.
 */
final readonly class HamburgInfiltration
{
    public const string SOURCE = 'infiltration';
    public const string DATASET = 'versickerung';
    public const string COLLECTION = 'versickerungspotential';
    private const int CACHE_SECONDS = 86400;
    private const int CACHE_VERSION = 1;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const int DECIMALS = 1;

    public function __construct(
        private HamburgApi $api,
        private LoggerInterface $logger,
        private CacheInterface $cache,
    ) {
    }

    /**
     * The infiltration potential around a site, in plan metres.
     *
     * @param list<DataSource> $sources the sources of the site's other layers
     */
    public function around(GeoPoint $centre, int $radiusMetres, array $sources = []): SiteConditions
    {
        if (!Hamburg::covers($centre)) {
            return new SiteConditions([], $sources);
        }
        // only the part around the site is kept of each area; that is what is cached
        $key = sprintf('hamburg_infiltration_v%d_%s_%s_%d', self::CACHE_VERSION, round($centre->latitude, 5), round($centre->longitude, 5), $radiusMetres);
        $areas = $this->unlessFailing(fn (): array => $this->cache->get($key, function (ItemInterface $item) use ($centre, $radiusMetres): array {
            $item->expiresAfter(self::CACHE_SECONDS);

            return $this->infiltrationAreas($centre, $radiusMetres);
        }));
        if (null === $areas) {
            return new SiteConditions([], $sources);
        }

        return new SiteConditions($areas, [...$sources, self::source()]);
    }

    public static function source(): DataSource
    {
        return new DataSource(self::SOURCE, 'Versickerungspotentialkarte Hamburg', Hamburg::BUKEA, '2024', Hamburg::LICENCE, HamburgApi::BASE_URL.'/'.self::DATASET,
            'A preliminary assessment for planning, not a substitute for a percolation test; it gives no infiltration rate, and says nothing about contamination, utilities or building distances.');
    }

    /**
     * The infiltration areas around a site in plan metres, cut to the square: read one area at a
     * time, as some reach across whole districts.
     *
     * @return list<array<string, mixed>>
     */
    private function infiltrationAreas(GeoPoint $centre, int $radiusMetres): array
    {
        $projection = new LocalProjection($centre);
        $toPlan = static fn (array $ring): array => array_map(static function (array $point) use ($projection): array {
            [$x, $z] = $projection->toPlan((float) $point[1], (float) $point[0]);

            return [round($x / self::MILLIMETRES_PER_METRE, self::DECIMALS), round($z / self::MILLIMETRES_PER_METRE, self::DECIMALS)];
        }, $ring);
        $areas = [];
        $this->api->each(self::DATASET, self::COLLECTION, GeoBox::around($centre, $radiusMetres), static function (array $feature) use (&$areas, $toPlan, $radiusMetres): void {
            $p = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $text = static fn (string $key): string => is_string($p[$key] ?? null) ? $p[$key] : '';
            foreach (self::polygons($feature['geometry'] ?? null) as [$outer, $holes]) {
                $points = self::clipToSquare($toPlan($outer), $radiusMetres);
                if (count($points) < 3) {
                    continue;
                }
                $areas[] = [
                    SiteConditions::POINTS => $points,
                    SiteConditions::HOLES => array_values(array_filter(
                        array_map(static fn (array $hole): array => self::clipToSquare($toPlan($hole), $radiusMetres), $holes),
                        static fn (array $hole): bool => count($hole) >= 3,
                    )),
                    SiteConditions::POTENTIAL => InfiltrationPotential::fromGerman($text('versickerungswahrscheinlichkeit'))->value,
                    SiteConditions::DEPTH => $text('versickerungsfaehige_tiefe'),
                    SiteConditions::FACILITY => $text('versickerungsanlage'),
                ];
            }
        });

        return $areas;
    }

    /**
     * Every part of a (Multi)Polygon as its outer ring and its holes, without closing points.
     *
     * @return list<array{list<array{0: float, 1: float}>, list<list<array{0: float, 1: float}>>}> [longitude, latitude]
     */
    private static function polygons(mixed $geometry): array
    {
        if (!is_array($geometry) || !is_array($geometry['coordinates'] ?? null)) {
            return [];
        }
        $parts = match ($geometry['type'] ?? null) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'],
            default => [],
        };
        $polygons = [];
        foreach ($parts as $rings) {
            $rings = array_map(static function (mixed $ring): array {
                $ring = is_array($ring) ? array_values(array_filter($ring, static fn (mixed $p): bool => is_array($p) && isset($p[0], $p[1]))) : [];
                if (count($ring) > 1 && $ring[0] === end($ring)) {
                    array_pop($ring);
                }

                return $ring;
            }, is_array($rings) ? array_values($rings) : []);
            if (count($rings[0] ?? []) >= 3) {
                $polygons[] = [$rings[0], array_slice($rings, 1)];
            }
        }

        return $polygons;
    }

    /**
     * The part of a closed outline inside a square around the origin (Sutherland-Hodgman).
     *
     * @param list<array{0: float, 1: float}> $points
     *
     * @return list<array{0: float, 1: float}>
     */
    private static function clipToSquare(array $points, float $half): array
    {
        foreach ([[0, 1], [0, -1], [1, 1], [1, -1]] as [$axis, $sign]) {
            $inside = static fn (array $p): bool => $sign * $p[$axis] <= $half;
            $clipped = [];
            for ($i = 0, $count = count($points); $i < $count; ++$i) {
                [$current, $previous] = [$points[$i], $points[($i + $count - 1) % $count]];
                if ($inside($current) !== $inside($previous)) {
                    $t = ($sign * $half - $previous[$axis]) / ($current[$axis] - $previous[$axis]);
                    $clipped[] = [round($previous[0] + $t * ($current[0] - $previous[0]), self::DECIMALS), round($previous[1] + $t * ($current[1] - $previous[1]), self::DECIMALS)];
                }
                if ($inside($current)) {
                    $clipped[] = $current;
                }
            }
            $points = $clipped;
        }

        return $points;
    }

    /**
     * @param callable(): list<array<string, mixed>> $fetch
     *
     * @return list<array<string, mixed>>|null null when the service failed (the site has no conditions then)
     */
    private function unlessFailing(callable $fetch): ?array
    {
        try {
            return $fetch();
        } catch (\Throwable $exception) {
            $this->logger->warning('Hamburg infiltration map unavailable', ['exception' => $exception]);

            return null;
        }
    }
}

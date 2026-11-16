<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\GeoBox;
use App\SpongeCity\Geo\SiteImporter;

/**
 * Hamburg's street tree register (Straßenbaumkataster): every tree the city maintains
 * along its streets with species, planting year and crown diameter. Returned as
 * Overpass-like natural=tree nodes with OpenStreetMap tags.
 *
 * The register has no heights: a tree's height is estimated from its crown (urban
 * broadleaf trees stand roughly 1.3 times as tall as their crown is wide, plus the trunk).
 */
final readonly class HamburgTrees
{
    public const string DATASET = 'strassenbaumkataster';
    public const string COLLECTION = 'strassenbaumkataster_hh';

    public const string TAG_CROWN = SiteImporter::TAG_CROWN;
    public const string TAG_SPECIES = SiteImporter::TAG_SPECIES;
    public const string TAG_PLANTED = SiteImporter::TAG_PLANTED;
    public const string TAG_REF = 'ref';
    public const string SOURCE = 'Straßenbaumkataster Hamburg';

    public const float HEIGHT_PER_CROWN = 1.3;
    public const float TRUNK_METRES = 2.0;
    public const float MIN_HEIGHT_METRES = 4.0;
    public const float MAX_HEIGHT_METRES = 25.0;

    public function __construct(
        private HamburgApi $api,
    ) {
    }

    /**
     * @return list<array<string, mixed>> Overpass-like nodes (type, lat, lon, tags)
     */
    public function within(GeoBox $box): array
    {
        $nodes = [];
        foreach ($this->api->features(self::DATASET, self::COLLECTION, $box) as $feature) {
            $position = self::position($feature['geometry'] ?? null);
            if (null === $position) {
                continue;
            }
            $p = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $crown = is_numeric($p['kronendurchmesser'] ?? null) ? (float) $p['kronendurchmesser'] : null;
            $tags = [SiteImporter::TAG_NATURAL => 'tree', HamburgBuildings::TAG_SOURCE => self::SOURCE];
            if (null !== $crown && $crown > 0) {
                $tags[self::TAG_CROWN] = (string) $crown;
                $tags[SiteImporter::TAG_HEIGHT] = (string) self::heightFromCrown($crown);
            }
            if (is_string($p['art_deutsch'] ?? null) && '' !== $p['art_deutsch']) {
                $tags[self::TAG_SPECIES] = $p['art_deutsch'];
            }
            if (is_numeric($p['pflanzjahr'] ?? null)) {
                $tags[self::TAG_PLANTED] = (string) (int) $p['pflanzjahr'];
            }
            if (is_string($p['baumnummer'] ?? null)) {
                $tags[self::TAG_REF] = $p['baumnummer'];
            }
            $nodes[] = ['type' => 'node', 'lat' => $position[1], 'lon' => $position[0], 'tags' => $tags];
        }

        return $nodes;
    }

    public static function heightFromCrown(float $crownMetres): float
    {
        return round(max(self::MIN_HEIGHT_METRES, min(self::MAX_HEIGHT_METRES, $crownMetres * self::HEIGHT_PER_CROWN + self::TRUNK_METRES)), 1);
    }

    /**
     * @return array{0: float, 1: float}|null [longitude, latitude] of a Point or the first of a MultiPoint
     */
    private static function position(mixed $geometry): ?array
    {
        if (!is_array($geometry)) {
            return null;
        }
        $point = match ($geometry['type'] ?? null) {
            'Point' => $geometry['coordinates'] ?? null,
            'MultiPoint' => $geometry['coordinates'][0] ?? null,
            default => null,
        };

        return is_array($point) && is_numeric($point[0] ?? null) && is_numeric($point[1] ?? null) ? [(float) $point[0], (float) $point[1]] : null;
    }
}

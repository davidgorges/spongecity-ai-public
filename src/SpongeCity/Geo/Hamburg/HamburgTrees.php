<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo\Hamburg;

use App\SpongeCity\Geo\GeoBox;
use App\SpongeCity\Geo\SiteImporter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Hamburg's street tree register (Straßenbaumkataster): every tree the city maintains
 * along its streets with species, planting year and crown diameter. Returned as
 * Overpass-like natural=tree nodes with OpenStreetMap tags.
 *
 * Heights come from the "3D Straßenbaumkataster Hamburg" (LGV): the highest point of the
 * airborne laser scan (April 2022) within the crown above the ground, joined by the tree
 * number. Where it has none (a tree the scan could not measure, or the layer is
 * unavailable) the height is estimated from the crown: urban broadleaf trees stand
 * roughly 1.3 times as tall as their crown is wide, plus the trunk.
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
    /** the 3D street tree register's layer and its attributes */
    public const string LAYER_3D = 'Strassenbaumkataster_Sommerbaeume';
    private const string ATTRIBUTE_NUMBER = 'Baumnummer';
    private const string ATTRIBUTE_HEIGHT = 'Hoehe_aus_ALS';
    /** how the height was found: measured (laser scan) or estimated from the crown */
    public const string TAG_HEIGHT_SOURCE = 'height:source';
    public const string HEIGHT_MEASURED = 'als';
    public const string HEIGHT_ESTIMATED = 'estimate';

    public const float HEIGHT_PER_CROWN = 1.3;
    public const float TRUNK_METRES = 2.0;
    public const float MIN_HEIGHT_METRES = 4.0;
    public const float MAX_HEIGHT_METRES = 25.0;

    public function __construct(
        private HamburgApi $api,
        private Hamburg3dTiles $tiles,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return list<array<string, mixed>> Overpass-like nodes (type, lat, lon, tags)
     */
    public function within(GeoBox $box): array
    {
        $measured = $this->measuredHeights($box);
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
            }
            $height = $measured[(string) ($p['baumnummer'] ?? '')] ?? null;
            if (null !== $height) {
                [$tags[SiteImporter::TAG_HEIGHT], $tags[self::TAG_HEIGHT_SOURCE]] = [(string) $height, self::HEIGHT_MEASURED];
            } elseif (null !== $crown && $crown > 0) {
                [$tags[SiteImporter::TAG_HEIGHT], $tags[self::TAG_HEIGHT_SOURCE]] = [(string) self::heightFromCrown($crown), self::HEIGHT_ESTIMATED];
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

    /**
     * Laser-scanned heights by tree number; empty when the 3D layer cannot be read
     * (the heights are then estimated).
     *
     * @return array<string, float>
     */
    private function measuredHeights(GeoBox $box): array
    {
        try {
            $heights = [];
            foreach ($this->tiles->attributes(self::LAYER_3D, $box) as $tree) {
                $number = $tree[self::ATTRIBUTE_NUMBER] ?? null;
                $height = $tree[self::ATTRIBUTE_HEIGHT] ?? null;
                if (is_string($number) && is_numeric($height) && (float) $height > 0) {
                    $heights[$number] = round((float) $height, 1);
                }
            }

            return $heights;
        } catch (\Throwable $exception) {
            $this->logger->warning('3D street tree register unavailable, tree heights are estimated', ['exception' => $exception]);

            return [];
        }
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

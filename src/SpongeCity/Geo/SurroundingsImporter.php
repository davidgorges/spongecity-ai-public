<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;

/**
 * Turns the OpenStreetMap buildings and streets around a site into its Surroundings:
 * every building with its height, façade and roof (BuildingTags, as for the site's
 * own), every street with its width. What the site already holds is left out, so
 * nothing is drawn twice.
 */
final readonly class SurroundingsImporter
{
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const int MAX_BUILDINGS = 1500;
    private const int MAX_ROADS = 600;
    /** coordinates in the view: a decimetre is plenty */
    private const int DECIMALS = 1;

    public function __construct(
        private BuildingTags $tags = new BuildingTags(),
    ) {
    }

    /**
     * @param list<array<string, mixed>> $elements raw Overpass ways with geometry
     */
    public function import(GeoPoint $centre, array $elements, Site $site, float $radiusMetres): Surroundings
    {
        $projection = new LocalProjection($centre);
        $siteBuildings = array_map(static fn (array $b): array => Site::points($b), $site->ofType(O::BUILDING));
        $buildings = [];
        $roads = [];
        foreach ($elements as $element) {
            $tags = is_array($element['tags'] ?? null) ? $element['tags'] : [];
            $points = $this->points($element, $projection);
            $highway = is_string($tags[SiteImporter::TAG_HIGHWAY] ?? null) ? $tags[SiteImporter::TAG_HIGHWAY] : '';
            if (isset($tags[SiteImporter::TAG_BUILDING]) && count($points) >= 3 && count($buildings) < self::MAX_BUILDINGS) {
                if (self::onSite($points, $siteBuildings)) {
                    continue;
                }
                $appearance = $this->tags->appearance($tags, $points);
                $buildings[] = [
                    'points' => self::metres($points),
                    'height' => $this->tags->height($tags) / self::MILLIMETRES_PER_METRE,
                    'facade' => $appearance[O::FACADE],
                    'color' => $appearance[O::COLOR],
                    'roof' => $appearance[O::ROOF],
                    'roofColor' => $appearance[O::ROOF_COLOR],
                ];
            } elseif (isset(SiteImporter::ROAD_WIDTHS[$highway]) && count($points) >= 2 && count($roads) < self::MAX_ROADS) {
                $roads[] = [
                    'points' => self::metres($points),
                    'width' => SiteImporter::ROAD_WIDTHS[$highway] / self::MILLIMETRES_PER_METRE,
                    'kind' => in_array($highway, SiteImporter::FOOTWAYS, true) ? O::ROAD_FOOTWAY : O::ROAD_CARRIAGEWAY,
                ];
            }
        }

        return new Surroundings($radiusMetres, $buildings, $roads);
    }

    /**
     * Whether a building is one of the site's own: one of its corners, or its middle,
     * lies in a building of the site (which may have been cut at the site's edge).
     *
     * @param list<array{0: int, 1: int}>     $points
     * @param list<list<array{float, float}>> $siteBuildings
     */
    private static function onSite(array $points, array $siteBuildings): bool
    {
        $middle = [array_sum(array_column($points, 0)) / count($points), array_sum(array_column($points, 1)) / count($points)];
        foreach ($siteBuildings as $polygon) {
            foreach ([$middle, ...$points] as [$x, $z]) {
                if (Polygon::contains($polygon, $x, $z)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $element
     *
     * @return list<array{0: int, 1: int}> plan millimetres
     */
    private function points(array $element, LocalProjection $projection): array
    {
        $points = [];
        foreach (is_array($element['geometry'] ?? null) ? $element['geometry'] : [] as $node) {
            if (is_array($node) && isset($node['lat'], $node['lon'])) {
                $points[] = $projection->toPlan((float) $node['lat'], (float) $node['lon']);
            }
        }
        // closed ways repeat their first node
        if (count($points) > 3 && $points[0] === end($points)) {
            array_pop($points);
        }

        return $points;
    }

    /**
     * @param list<array{0: int, 1: int}> $points
     *
     * @return list<array{float, float}>
     */
    private static function metres(array $points): array
    {
        return array_map(static fn (array $p): array => [round($p[0] / self::MILLIMETRES_PER_METRE, self::DECIMALS), round($p[1] / self::MILLIMETRES_PER_METRE, self::DECIMALS)], $points);
    }
}

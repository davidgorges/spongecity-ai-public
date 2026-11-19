<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Core\Configurator\Site\SiteObject as O;

/**
 * What an OpenStreetMap area on a plot is used for and what its ground is made of: parking,
 * pitches, playgrounds, service and pedestrian areas, grass. The use decides what the surface may
 * become (PermeableSiting); the surface is what the balance counts today.
 */
final readonly class AreaTags
{
    public const string TAG_AREA = 'area';
    public const string TAG_AREA_HIGHWAY = 'area:highway';
    public const string YES = 'yes';
    public const string TAG_SURFACE = 'surface';
    public const string TAG_HIGHWAY = 'highway';

    /** tag => value => use */
    private const array USES = [
        'amenity' => ['parking' => O::AREA_PARKING, 'bicycle_parking' => O::AREA_PARKING],
        'leisure' => ['pitch' => O::AREA_SPORT, 'track' => O::AREA_SPORT, 'playground' => O::AREA_PLAY, 'garden' => O::AREA_GREEN],
        'landuse' => ['grass' => O::AREA_GREEN, 'flowerbed' => O::AREA_GREEN, 'village_green' => O::AREA_GREEN],
        'natural' => ['scrub' => O::AREA_GREEN, 'grassland' => O::AREA_GREEN, 'heath' => O::AREA_GREEN],
    ];
    /** highway=* with area=yes, or area:highway=* */
    private const array HIGHWAY_USES = ['service' => O::AREA_ACCESS, 'pedestrian' => O::AREA_YARD, 'footway' => O::AREA_YARD];
    /** surface=* → the site's surfaces */
    private const array SURFACES = [
        'asphalt' => O::SURFACE_ASPHALT,
        'concrete' => O::SURFACE_CONCRETE, 'concrete:plates' => O::SURFACE_CONCRETE, 'paving_stones' => O::SURFACE_CONCRETE,
        'sett' => O::SURFACE_CONCRETE, 'paved' => O::SURFACE_CONCRETE, 'tartan' => O::SURFACE_CONCRETE,
        'gravel' => O::SURFACE_GRAVEL, 'fine_gravel' => O::SURFACE_GRAVEL, 'compacted' => O::SURFACE_GRAVEL, 'pebblestone' => O::SURFACE_GRAVEL,
        'grass' => O::SURFACE_LAWN, 'grass_paver' => O::SURFACE_LAWN, 'artificial_turf' => O::SURFACE_LAWN,
        'dirt' => O::SURFACE_SOIL, 'ground' => O::SURFACE_SOIL, 'earth' => O::SURFACE_SOIL, 'sand' => O::SURFACE_SOIL, 'woodchips' => O::SURFACE_SOIL, 'unpaved' => O::SURFACE_SOIL,
    ];
    /** without a surface tag: what such an area usually is */
    private const array DEFAULT_SURFACES = [
        O::AREA_PARKING => O::SURFACE_ASPHALT, O::AREA_SPORT => O::SURFACE_ASPHALT, O::AREA_PLAY => O::SURFACE_SOIL,
        O::AREA_GREEN => O::SURFACE_LAWN, O::AREA_ACCESS => O::SURFACE_ASPHALT, O::AREA_YARD => O::SURFACE_CONCRETE,
    ];

    /**
     * @param array<string, mixed> $tags
     *
     * @return array{use: string, surface: string}|null null: not a ground area of a plot
     */
    public function of(array $tags): ?array
    {
        $use = $this->use($tags);
        if (null === $use) {
            return null;
        }
        $surface = is_string($tags[self::TAG_SURFACE] ?? null) ? (self::SURFACES[$tags[self::TAG_SURFACE]] ?? null) : null;

        return ['use' => $use, 'surface' => $surface ?? self::DEFAULT_SURFACES[$use]];
    }

    /**
     * @param array<string, mixed> $tags
     */
    public function isHighwayArea(array $tags): bool
    {
        return isset($tags[self::TAG_AREA_HIGHWAY]) || (self::YES === ($tags[self::TAG_AREA] ?? null) && isset($tags[self::TAG_HIGHWAY]));
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function use(array $tags): ?string
    {
        if ($this->isHighwayArea($tags)) {
            $highway = $tags[self::TAG_AREA_HIGHWAY] ?? $tags[self::TAG_HIGHWAY];

            return is_string($highway) ? (self::HIGHWAY_USES[$highway] ?? null) : null;
        }
        foreach (self::USES as $key => $values) {
            if (is_string($tags[$key] ?? null) && isset($values[$tags[$key]])) {
                return $values[$tags[$key]];
            }
        }

        return null;
    }
}

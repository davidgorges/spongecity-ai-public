<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

/**
 * Equirectangular projection around a centre: good to centimetres within a few hundred metres.
 * Plan coordinates are millimetres, x to the east, z to the south (towards the viewer).
 */
final readonly class LocalProjection
{
    private const float EARTH_RADIUS_METRES = 6_371_000.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;

    public function __construct(private GeoPoint $centre)
    {
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function toPlan(float $latitude, float $longitude): array
    {
        $x = deg2rad($longitude - $this->centre->longitude) * cos(deg2rad($this->centre->latitude)) * self::EARTH_RADIUS_METRES;
        $north = deg2rad($latitude - $this->centre->latitude) * self::EARTH_RADIUS_METRES;

        return [(int) round($x * self::MILLIMETRES_PER_METRE), (int) round(-$north * self::MILLIMETRES_PER_METRE)];
    }

    /**
     * The inverse: plan metres (x east, z south) to latitude/longitude.
     *
     * @return array{0: float, 1: float} [latitude, longitude]
     */
    public function toGeo(float $xMetres, float $zMetres): array
    {
        $latitude = $this->centre->latitude + rad2deg(-$zMetres / self::EARTH_RADIUS_METRES);
        $longitude = $this->centre->longitude + rad2deg($xMetres / (self::EARTH_RADIUS_METRES * cos(deg2rad($this->centre->latitude))));

        return [$latitude, $longitude];
    }
}

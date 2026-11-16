<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

/**
 * A square in longitude/latitude around a point, for bounding-box queries (OGC API bbox).
 */
final readonly class GeoBox
{
    private const float METRES_PER_DEGREE = 111_195.0;
    private const int DECIMALS = 6;

    public function __construct(
        public float $west,
        public float $south,
        public float $east,
        public float $north,
    ) {
    }

    public static function around(GeoPoint $centre, float $halfSideMetres): self
    {
        $dLat = $halfSideMetres / self::METRES_PER_DEGREE;
        $dLon = $dLat / cos(deg2rad($centre->latitude));

        return new self($centre->longitude - $dLon, $centre->latitude - $dLat, $centre->longitude + $dLon, $centre->latitude + $dLat);
    }

    public function contains(GeoPoint $point): bool
    {
        return $point->longitude >= $this->west && $point->longitude <= $this->east
            && $point->latitude >= $this->south && $point->latitude <= $this->north;
    }

    /** "west,south,east,north" (CRS84 axis order) */
    public function toBbox(): string
    {
        return implode(',', array_map(static fn (float $v): string => number_format($v, self::DECIMALS, '.', ''), [$this->west, $this->south, $this->east, $this->north]));
    }
}

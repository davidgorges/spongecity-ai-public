<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

/**
 * The part of the map a site or its surroundings are read from.
 */
final readonly class MapArea
{
    public function __construct(
        public GeoPoint $centre,
        public int $radiusMetres,
        public MapExtent $extent,
    ) {
    }

    public static function site(GeoPoint $centre, int $radiusMetres): self
    {
        return new self($centre, $radiusMetres, MapExtent::Site);
    }

    public static function surroundings(GeoPoint $centre, int $radiusMetres): self
    {
        return new self($centre, $radiusMetres, MapExtent::Surroundings);
    }

    public function box(): GeoBox
    {
        return GeoBox::around($this->centre, $this->radiusMetres);
    }
}

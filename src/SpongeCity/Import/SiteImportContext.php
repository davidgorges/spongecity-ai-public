<?php

declare(strict_types=1);

namespace App\SpongeCity\Import;

use App\SpongeCity\Geo\DataSource;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Terrain\Terrain;

/**
 * What the steps of a site import hand on to each other.
 */
final class SiteImportContext
{
    /** @var list<array<string, mixed>> Overpass-like map elements */
    public array $elements = [];
    /** @var list<DataSource> the official layers the site was built from */
    public array $sources = [];
    /** the site as the configurator stores it */
    public string $siteJson = '';
    public ?Terrain $terrain = null;

    public function __construct(
        public readonly string $planId,
        public readonly GeoPoint $centre,
        public readonly int $radiusMetres,
    ) {
    }
}

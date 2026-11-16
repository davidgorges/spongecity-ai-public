<?php

declare(strict_types=1);

namespace App\SpongeCity\Import;

/**
 * Asks a worker to rebuild the surroundings of a plan from OpenStreetMap.
 */
final readonly class ImportSite
{
    /** the surroundings of a new plan: buildings and trees within this distance */
    public const int DEFAULT_RADIUS_METRES = 80;
    /** the real buildings and streets drawn around the site, out to this distance */
    public const int SURROUNDINGS_RADIUS_METRES = 350;
    /** the site conditions (infiltration potential) are kept out to this distance: the site's reach */
    public const int CONDITIONS_RADIUS_METRES = 100;

    public function __construct(
        public string $planId,
        public float $latitude,
        public float $longitude,
        public int $radiusMetres,
    ) {
    }
}

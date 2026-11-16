<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use App\SpongeCity\Geo\Hamburg\HamburgGeodata;
use App\SpongeCity\Import\ImportSite;
use App\SpongeCity\Plan\Plan;
use Core\Configurator\Site\Site;
use Psr\Log\LoggerInterface;

/**
 * The real surroundings of a plan: the stored ones, or imported from OpenStreetMap the
 * first time they are asked for (plans imported before surroundings existed get theirs
 * without a re-import). Only context: when the map service fails there are none, and
 * the view draws a generic city.
 */
final readonly class SurroundingsLoader
{
    public function __construct(
        private OverpassClient $overpass,
        private SurroundingsImporter $importer,
        private SurroundingsStore $store,
        private LoggerInterface $logger,
        private HamburgGeodata $hamburg,
    ) {
    }

    public function load(Plan $plan): Surroundings
    {
        return $this->store->find($plan->id) ?? $this->import($plan) ?? Surroundings::none();
    }

    /** Imports and stores them; null when the map service fails (asked again next time). */
    public function import(Plan $plan): ?Surroundings
    {
        if (!$plan->isReady()) {
            return null;
        }
        try {
            $centre = new GeoPoint($plan->latitude, $plan->longitude, $plan->title);
            $elements = $this->hamburg->surroundings($centre, $this->overpass->surroundings($centre, ImportSite::SURROUNDINGS_RADIUS_METRES), ImportSite::SURROUNDINGS_RADIUS_METRES);
            $surroundings = $this->importer->import($centre, $elements, Site::fromJson($plan->siteJson()), ImportSite::SURROUNDINGS_RADIUS_METRES);
        } catch (\Throwable $exception) {
            $this->logger->warning('OpenStreetMap surroundings could not be imported', ['plan' => $plan->id, 'exception' => $exception]);

            return null;
        }
        $this->store->save($plan->id, $surroundings);

        return $surroundings;
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Import;

use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\Hamburg\HamburgGeodata;
use App\SpongeCity\Geo\OverpassClient;
use App\SpongeCity\Geo\SiteImporter;
use App\SpongeCity\Geo\SurroundingsLoader;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Terrain\SiteGrounding;
use App\SpongeCity\Terrain\TerrainStore;
use Core\Configurator\Site\SiteType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class ImportSiteHandler
{
    public function __construct(
        private OverpassClient $overpass,
        private SiteImporter $importer,
        private PlanStore $plans,
        private LoggerInterface $logger,
        private SurroundingsLoader $surroundings,
        private HamburgGeodata $hamburg,
        private SiteConditionsStore $conditions,
        private TerrainStore $terrains,
        private SiteGrounding $grounding = new SiteGrounding(),
    ) {
    }

    public function __invoke(ImportSite $message): void
    {
        $plan = $this->plans->find($message->planId);
        if (null === $plan) {
            throw new UnrecoverableMessageHandlingException(sprintf('Plan "%s" is gone.', $message->planId));
        }

        try {
            $centre = new GeoPoint($message->latitude, $message->longitude, $plan->title);
            // in Hamburg, the city's own buildings and street trees replace OpenStreetMap's (each layer falls back on its own)
            [$elements, $sources] = $this->hamburg->site($centre, $this->overpass->around($centre, $message->radiusMetres), $message->radiusMetres);
            $import = $this->importer->import($centre, $elements);
        } catch (\Throwable $exception) {
            $this->logger->error('OpenStreetMap import failed', ['plan' => $plan->id, 'exception' => $exception]);
            $this->plans->save($plan->withStatus(Plan::STATUS_FAILED));

            throw $exception;
        }

        // the ground (terrain model): upright objects stand on it, the view drapes the rest over it
        $terrain = $this->hamburg->terrain($centre);
        $this->terrains->save($plan->id, $terrain);
        if (null !== $terrain) {
            $sources[] = $terrain->source;
        }
        // what official data says about the ground (infiltration potential), with the sources of every layer
        $this->conditions->save($plan->id, $this->hamburg->conditions($centre, ImportSite::CONDITIONS_RADIUS_METRES, $sources));
        // normalised now, so every object has its id from the start (the agent addresses buildings by it)
        $siteJson = $this->grounding->ground((new SiteType())->normalize($import->siteJson), $terrain);
        $this->plans->save($plan->withLocation($message->latitude, $message->longitude)->withValues([...$plan->values, SiteType::PARAMETER => $siteJson])->withStatus(Plan::STATUS_READY));
        // the real city around the site, for the 3D view (context only: may fail without failing the plan)
        $this->surroundings->import($this->plans->get($plan->id));
    }
}

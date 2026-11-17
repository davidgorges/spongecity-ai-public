<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\Hamburg\HamburgCityGml;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Site\SiteObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs a SimStadt workflow for a plan: the site's buildings as CityGML from Hamburg's
 * 3D building model, SimStadt in its Docker image, the results condensed per building.
 * A failure is stored with the plan's simulation (the agent reads the reason), not retried.
 */
#[AsMessageHandler]
final readonly class RunSimulationHandler
{
    private const float MILLIMETRES_PER_METRE = 1000.0;

    public function __construct(
        private PlanStore $plans,
        private SimulationStore $simulations,
        private SimulationRun $run,
        private HamburgCityGml $cityGml,
        private SimStadtRunner $runner,
        private SimStadtResults $results,
        private SimStadtTemplates $templates,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunSimulation $message): void
    {
        $simulation = $this->simulations->find($message->planId, $message->workflow)
            ?? new Simulation($message->planId, $message->workflow, $message->yearOfConstruction);
        $plan = $this->plans->find($message->planId);
        if (null === $plan) {
            $this->fail($simulation, 'The plan is gone.');

            return;
        }
        $this->run->apply($simulation, SimulationRun::START);

        try {
            $centre = new GeoPoint($plan->latitude, $plan->longitude, $plan->title);
            // the buildings within the site's reach, as the 3D view and the impact calculation see them
            $extract = $this->cityGml->extract($centre, SiteObject::REACH / self::MILLIMETRES_PER_METRE, $message->yearOfConstruction);
            if ([] === $extract['buildings']) {
                $this->fail($simulation, 'Hamburg\'s 3D building model has no buildings around this site.');

                return;
            }
            $template = $this->templates->for($message->workflow);
            $output = $this->runner->run($template, $extract['gml']);
            $simulation->recordResults(
                $output['seconds'],
                $this->results->summary($output['output']),
                $this->results->buildings($output['csv'], $template, $centre),
                $this->assumptions($message, count($extract['buildings'])),
            );
            $this->run->apply($simulation, SimulationRun::FINISH);
        } catch (\Throwable $exception) {
            $this->logger->error('SimStadt simulation failed', ['plan' => $message->planId, 'workflow' => $message->workflow->value, 'exception' => $exception]);
            $this->fail($simulation, $exception->getMessage());
        }
    }

    private function fail(Simulation $simulation, string $reason): void
    {
        $simulation->recordError($reason);
        $this->run->apply($simulation, SimulationRun::FAIL);
    }

    /**
     * @return list<string>
     */
    private function assumptions(RunSimulation $message, int $buildings): array
    {
        return [
            sprintf('%d buildings of Hamburg\'s 3D building model (LoD2-DE, %s) within %d m of the site\'s centre, as CityGML.', $buildings, HamburgCityGml::AS_OF, (int) (SiteObject::REACH / self::MILLIMETRES_PER_METRE)),
            sprintf('Construction year %d for every building: the model has none, and SimStadt derives walls, windows and roofs (U-values) from it. Heat demand depends strongly on it.', $message->yearOfConstruction),
            'Uses from the cadastre\'s building function (ALKIS codes) as SimStadt maps them; no refurbishment.',
            'Weather from SimStadt\'s own sources for the site (PVGIS SARAH3 2005–2023, INSEL station database).',
            'SimStadt 2 (HFT Stuttgart, MIT licence) in its Docker image: a monthly energy balance after DIN V 18599 for heat, roof surfaces for PV.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Controller\Plan\ShowPlanController;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Import\ImportSite;
use App\SpongeCity\Import\PlanImporter;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteCarryOver;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Rebuilds a plan's site and surroundings from OpenStreetMap right away (no worker),
 * e.g. after the importer changed. The planned interventions and corrected looks are kept.
 */
#[AsCommand(name: 'app:plan:reimport', description: 'Re-imports the site and surroundings of a plan from OpenStreetMap, keeping its interventions')]
final class ReimportPlanCommand
{
    public function __construct(
        private readonly PlanStore $plans,
        private readonly PlanImporter $importer,
        private readonly SiteCarryOver $carryOver,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The plan id')]
        string $id,
        #[Option(description: 'Radius of the surroundings in metres')]
        int $radius = ImportSite::DEFAULT_RADIUS_METRES,
    ): int {
        $plan = $this->plans->get($id);
        $kept = $this->carryOver->capture($plan->id);
        if (!$this->importer->request($plan, new GeoPoint($plan->latitude, $plan->longitude, $plan->title), $radius, now: true)) {
            $io->error(sprintf('Plan %s is being imported right now (%s).', $plan->id, $plan->status->value));

            return Command::FAILURE;
        }
        $restored = $this->carryOver->restore($plan->id, $kept);
        foreach ($restored['conflicts'] as $conflict) {
            $io->warning(sprintf('Left out %s "%s": %s', $conflict['type'], $conflict['label'] ?? '', $conflict['reason']));
        }
        $io->success(sprintf('Re-imported %s, kept %d interventions and %d corrected building looks', $this->urls->generate(ShowPlanController::ROUTE, ['id' => $plan->id], UrlGeneratorInterface::ABSOLUTE_URL), count($kept->interventions) - count($restored['conflicts']), $restored['looks']));

        return Command::SUCCESS;
    }
}

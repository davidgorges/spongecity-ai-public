<?php

declare(strict_types=1);

namespace App\Command;

use App\SpongeCity\Import\ImportSite;
use App\SpongeCity\Import\ImportSiteHandler;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use Core\Configurator\Site\SiteObject as O;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds a plan's site and surroundings from OpenStreetMap right away (no worker),
 * e.g. after the importer changed. The planned interventions are kept.
 */
#[AsCommand(name: 'app:plan:reimport', description: 'Re-imports the site and surroundings of a plan from OpenStreetMap, keeping its interventions')]
final class ReimportPlanCommand
{
    public function __construct(
        private readonly PlanStore $plans,
        private readonly ImportSiteHandler $handler,
        private readonly SiteEditor $editor,
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
        $interventions = array_values(array_filter(
            $this->editor->site($plan->id)->objects,
            static fn (array $object): bool => in_array($object[O::TYPE] ?? null, O::INTERVENTIONS, true),
        ));
        ($this->handler)(new ImportSite($plan->id, $plan->latitude, $plan->longitude, $radius));
        if ([] !== $interventions) {
            $this->editor->add($plan->id, $interventions);
        }
        $io->success(sprintf('Re-imported /plan/%s, kept %d interventions', $plan->id, count($interventions)));

        return Command::SUCCESS;
    }
}

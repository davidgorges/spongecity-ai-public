<?php

declare(strict_types=1);

namespace App\Command;

use App\SpongeCity\Import\ImportSite;
use App\SpongeCity\Import\ImportSiteHandler;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
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
        $before = $this->editor->site($plan->id)->objects;
        $interventions = array_values(array_filter($before, static fn (array $object): bool => in_array($object[O::TYPE] ?? null, O::INTERVENTIONS, true)));
        // buildings whose look a person corrected: found again by where they stand
        $looks = array_values(array_filter($before, static fn (array $object): bool => O::BUILDING === ($object[O::TYPE] ?? null) && O::LOOK_USER === ($object[O::LOOK] ?? null)));
        ($this->handler)(new ImportSite($plan->id, $plan->latitude, $plan->longitude, $radius));
        if ([] !== $interventions) {
            $this->editor->add($plan->id, $interventions);
        }
        $kept = 0;
        foreach ($this->editor->site($plan->id)->ofType(O::BUILDING) as $building) {
            foreach ($looks as $look) {
                if (self::sameBuilding($building, $look)) {
                    $this->editor->update($plan->id, (string) $building[O::ID], [O::FACADE => $look[O::FACADE], O::COLOR => $look[O::COLOR]]);
                    ++$kept;
                    break;
                }
            }
        }
        $io->success(sprintf('Re-imported /plan/%s, kept %d interventions and %d corrected building looks', $plan->id, count($interventions), $kept));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function sameBuilding(array $a, array $b): bool
    {
        $points = Site::points($b);
        if ([] === $points) {
            return false;
        }
        $middle = [array_sum(array_column($points, 0)) / count($points), array_sum(array_column($points, 1)) / count($points)];

        return Polygon::contains(Site::points($a), $middle[0], $middle[1]);
    }
}

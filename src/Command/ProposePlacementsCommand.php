<?php

declare(strict_types=1);

namespace App\Command;

use App\SpongeCity\Placement\PlacementRequest;
use App\SpongeCity\Placement\Placements;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Site\SiteObject as O;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * What propose_placements tells the agent, on the console: to see where candidates land on a real
 * plan, and to place one (--place) as the agent would.
 */
#[AsCommand(name: 'app:plan:propose', description: 'Proposes where an intervention works on a plan, and optionally places a candidate')]
final readonly class ProposePlacementsCommand
{
    public function __construct(
        private PlanStore $plans,
        private Placements $placements,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The plan id')]
        string $id,
        #[Option(description: 'The intervention type')]
        string $type = O::RAINGARDEN,
        #[Option(description: 'The wanted area in m²')]
        ?float $area = null,
        #[Option(description: 'Near a point in plan millimetres, "x,z"')]
        ?string $near = null,
        #[Option(description: 'How many candidates')]
        int $count = PlacementRequest::DEFAULT_COUNT,
        #[Option(description: 'Place this candidate of the proposal (e.g. raingarden-1)')]
        ?string $place = null,
    ): int {
        $plan = $this->plans->get($id);
        if (null !== $place) {
            $result = $this->placements->place($plan, $place);
            $result['placed'] ? $io->success(sprintf('Placed %s as %s', $place, $result['id'] ?? '')) : $io->error($result['error'] ?? 'Not placed');

            return $result['placed'] ? Command::SUCCESS : Command::FAILURE;
        }
        if (!$this->placements->supports($type)) {
            $io->error(sprintf('No placement rules for "%s" (%s).', $type, implode(', ', $this->placements->types())));

            return Command::INVALID;
        }
        $point = null === $near ? null : array_map(intval(...), explode(',', $near, 2));
        $started = microtime(true);
        $proposal = $this->placements->propose($plan, new PlacementRequest($type, $area, $point, $count));
        $io->title(sprintf('%s on %s (%.1f s)', $type, $plan->title, microtime(true) - $started));
        foreach ($proposal->candidates as $candidate) {
            $io->section($candidate->id);
            $io->definitionList(...array_map(static fn (string $key, mixed $value): array => [$key => is_bool($value) ? ($value ? 'yes' : 'no') : (string) ($value ?? '–')], array_keys($candidate->metrics), $candidate->metrics));
            $io->listing($candidate->reasons);
            if ([] !== $candidate->warnings) {
                $io->warning($candidate->warnings);
            }
            $io->text('points: '.json_encode($candidate->object[O::POINTS]));
        }
        if (null !== $proposal->note) {
            $io->note($proposal->note);
        }
        $io->table(['excluded', 'share of plot'], array_map(static fn (string $reason, float $share): array => [$reason, sprintf('%.1f %%', $share * 100)], array_keys($proposal->excluded), $proposal->excluded));

        return Command::SUCCESS;
    }
}

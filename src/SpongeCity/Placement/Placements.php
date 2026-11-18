<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\Terrain\TerrainAnalysis;
use App\SpongeCity\Terrain\TerrainStore;
use Core\Configurator\Site\SiteObject as O;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Where interventions work on a plan: proposes candidates by the rules of their type (one
 * InterventionSiting each), keeps them, and places the one the agent chose, checked again
 * against the plan as it is by then.
 */
final readonly class Placements
{
    /**
     * @param ServiceLocator<InterventionSiting> $sitings
     */
    public function __construct(
        #[AutowireLocator(InterventionSiting::class)]
        private ServiceLocator $sitings,
        private ProposalStore $proposals,
        private TerrainStore $terrains,
        private SiteConditionsStore $conditions,
        private TerrainAnalysis $analysis,
        private SiteEditor $editor,
    ) {
    }

    /**
     * @return list<string> the intervention types with a siting
     */
    public function types(): array
    {
        return array_keys($this->sitings->getProvidedServices());
    }

    public function supports(string $type): bool
    {
        return $this->sitings->has($type);
    }

    public function propose(Plan $plan, PlacementRequest $request): Proposal
    {
        $proposal = $this->siting($request->type)->propose($this->context($plan), $request);
        $this->proposals->save($plan->id, $proposal);

        return $proposal;
    }

    /**
     * @return array{placed: bool, id?: string, candidate?: string, error?: string, conflicts?: list<array<string, mixed>>}
     */
    public function place(Plan $plan, string $candidateId, ?string $label = null): array
    {
        $type = $this->typeOf($candidateId);
        $candidate = null === $type ? null : $this->proposals->find($plan->id, $type)?->candidate($candidateId);
        if (null === $type || null === $candidate) {
            return ['placed' => false, 'error' => sprintf('No candidate "%s": propose placements first and use one of their ids.', $candidateId)];
        }
        $reason = $this->siting($type)->stillFits($this->context($plan), $candidate);
        if (null !== $reason) {
            return ['placed' => false, 'error' => $reason];
        }
        $result = $this->editor->add($plan->id, [[...$candidate->object, O::LABEL => $label]]);
        if ([] === $result['ids']) {
            return ['placed' => false, 'error' => 'The candidate could not be added.', 'conflicts' => $result['conflicts']];
        }

        return ['placed' => true, 'id' => $result['ids'][0], 'candidate' => $candidateId];
    }

    private function context(Plan $plan): PlacementContext
    {
        return PlacementContext::of($plan->id, $plan->siteJson(), $this->terrains->find($plan->id), $this->conditions->get($plan->id), $plan->storm(), $this->analysis);
    }

    private function siting(string $type): InterventionSiting
    {
        return $this->sitings->get($type);
    }

    /** a candidate's id starts with its type: "raingarden-2" */
    private function typeOf(string $candidateId): ?string
    {
        $type = strstr($candidateId, '-', true);

        return false !== $type && $this->supports($type) ? $type : null;
    }
}

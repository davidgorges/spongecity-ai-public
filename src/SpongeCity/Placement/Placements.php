<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\Terrain\TerrainAnalysis;
use App\SpongeCity\Terrain\TerrainStore;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Where interventions work on a plan: proposes candidates by the rules of their type (one
 * InterventionSiting each), keeps them, and places the one the agent chose, checked again
 * against the plan as it is by then.
 */
final readonly class Placements
{
    /** bump when the zoning changes, so zones of the old one are not reused */
    private const int ZONES_VERSION = 1;
    private const string ZONES_KEY = 'spongecity_zones_v%d_%s';

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
        private SurfaceZones $zones,
        private CacheInterface $cache,
    ) {
    }

    /**
     * The plot's zones (SurfaceZones), largest first. They follow from the site alone: a re-render
     * that leaves it as it was (a zone selected on the map) reads them back.
     *
     * @return list<Zone>
     */
    public function zones(Plan $plan): array
    {
        $site = $plan->siteJson();

        return $this->cache->get(sprintf(self::ZONES_KEY, self::ZONES_VERSION, hash('xxh128', $site)), function () use ($site): array {
            $objects = SiteType::rawObjects((new SiteType())->normalize($site));

            return $this->zones->of($objects, OpenGround::of($objects));
        });
    }

    /**
     * Says what a zone is, as a person knows it: what it is used for, what its ground is today, or
     * both. An area of the map gets them, open ground becomes an area of its own. Either way it is
     * the user's from then on (kept when the site is imported again). Green ground is lawn unless
     * said otherwise.
     *
     * @return string|null the zone's id from now on (open ground gets the id of its new area); null: no such zone, use or surface
     */
    public function describeZone(Plan $plan, string $zoneId, ?string $use = null, ?string $surface = null): ?string
    {
        if ((null === $use && null === $surface) || (null !== $use && !in_array($use, O::AREA_USES, true)) || (null !== $surface && !in_array($surface, O::SURFACES, true))) {
            return null;
        }
        foreach ($this->zones($plan) as $zone) {
            if ($zone->id !== $zoneId) {
                continue;
            }
            $use ??= $zone->use;
            $surface ??= O::AREA_GREEN === $use && O::SURFACE_UNKNOWN === $zone->surface ? O::SURFACE_LAWN : $zone->surface;
            $fields = [O::USE => $use, O::SURFACE => $surface, O::LOOK => O::LOOK_USER];
            if (null !== $zone->objectId) {
                return $this->editor->update($plan->id, $zone->objectId, $fields) ? $zone->objectId : null;
            }

            return $this->editor->add($plan->id, [[O::TYPE => O::AREA, O::POINTS => $zone->points(), ...$fields]])['ids'][0] ?? null;
        }

        return null;
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
        $result = $this->editor->add($plan->id, [[...$candidate->object, ...(null === $label ? [] : [O::LABEL => $label])]]);
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

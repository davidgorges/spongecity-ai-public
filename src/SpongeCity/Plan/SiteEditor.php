<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use App\SpongeCity\Terrain\SiteGrounding;
use App\SpongeCity\Terrain\TerrainStore;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;

/**
 * Changes the site of a plan through SiteType, which fills in defaults,
 * clamps every number and drops what it cannot read: nothing an agent sends
 * can break the scene.
 */
final readonly class SiteEditor
{
    public function __construct(
        private PlanStore $plans,
        private TerrainStore $terrains,
        private SiteGrounding $grounding = new SiteGrounding(),
    ) {
    }

    /**
     * Adds objects; returns their ids, in the order given, and what was left out.
     *
     * @param list<array<string, mixed>> $objects
     *
     * @return array{ids: list<string>, rejected: int}
     */
    public function add(string $planId, array $objects): array
    {
        $plan = $this->plans->get($planId);
        $normalizer = new SiteType();
        // objects from an import have no ids yet: give them theirs first, so only new ones count as new
        $current = SiteType::rawObjects($normalizer->normalize($plan->siteJson()));
        $known = array_column($current, O::ID);

        $additions = [];
        foreach ($objects as $object) {
            unset($object[O::ID]);
            $additions[] = $object;
        }
        $merged = $normalizer->normalize(['objects' => [...$current, ...$additions]]);
        $result = SiteType::rawObjects($merged);

        $ids = array_values(array_diff(array_column($result, O::ID), $known));
        $this->save($plan, $merged);

        return ['ids' => $ids, 'rejected' => max(0, count($objects) - count($ids))];
    }

    /**
     * @param array<string, mixed> $changes
     */
    public function update(string $planId, string $id, array $changes): bool
    {
        $plan = $this->plans->get($planId);
        $objects = SiteType::rawObjects($plan->siteJson());
        $found = false;
        foreach ($objects as $i => $object) {
            if (is_array($object) && ($object[O::ID] ?? null) === $id) {
                unset($changes[O::ID], $changes[O::TYPE]);
                // a building's look set by hand is kept when the site is imported again
                if (O::BUILDING === ($object[O::TYPE] ?? null) && (isset($changes[O::FACADE]) || isset($changes[O::COLOR]))) {
                    $changes[O::LOOK] = O::LOOK_USER;
                }
                $objects[$i] = [...$object, ...$changes];
                $found = true;
            }
        }
        if ($found) {
            $this->save($plan, (new SiteType())->normalize(['objects' => $objects]));
        }

        return $found;
    }

    public function remove(string $planId, string $id): bool
    {
        $plan = $this->plans->get($planId);
        $objects = SiteType::rawObjects($plan->siteJson());
        $kept = array_values(array_filter($objects, static fn (mixed $object): bool => !is_array($object) || ($object[O::ID] ?? null) !== $id));
        if (count($kept) === count($objects)) {
            return false;
        }
        $this->save($plan, (new SiteType())->normalize(['objects' => $kept]));

        return true;
    }

    /**
     * Removes every planned intervention, keeps the city as it is.
     */
    public function clearInterventions(string $planId): int
    {
        $plan = $this->plans->get($planId);
        $objects = SiteType::rawObjects($plan->siteJson());
        $kept = array_values(array_filter($objects, static fn (mixed $object): bool => !is_array($object) || !in_array($object[O::TYPE] ?? null, O::INTERVENTIONS, true)));
        $this->save($plan, (new SiteType())->normalize(['objects' => $kept]));

        return count($objects) - count($kept);
    }

    public function replaceSite(string $planId, string $siteJson): void
    {
        $this->save($this->plans->get($planId), (new SiteType())->normalize($siteJson));
    }

    public function site(string $planId): Site
    {
        return Site::fromJson($this->plans->get($planId)->siteJson());
    }

    private function save(Plan $plan, string $siteJson): void
    {
        // whatever was added or moved stands on the ground there
        $siteJson = $this->grounding->ground($siteJson, $this->terrains->find($plan->id));
        $this->plans->save($plan->withValues([...$plan->values, SiteType::PARAMETER => $siteJson]));
    }
}

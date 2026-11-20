<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use App\SpongeCity\Terrain\SiteGrounding;
use App\SpongeCity\Terrain\TerrainStore;
use Core\Configurator\Product\SpongeCity\GreenRoof\GreenRoofs;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;

/**
 * Changes the site of a plan through SiteType, which fills in defaults,
 * clamps every number and drops what it cannot read: nothing an agent sends
 * can break the scene. Interventions stay off buildings (BuildingClearance).
 */
final readonly class SiteEditor
{
    public function __construct(
        private PlanStore $plans,
        private TerrainStore $terrains,
        private SiteGrounding $grounding,
        private BuildingClearance $clearance,
    ) {
    }

    /** the fields that move an object: a change to one is checked against the buildings again */
    private const array PLACEMENT = [O::POINTS, O::X, O::Z];

    /**
     * Adds objects; returns their ids, in the order given, and what was left out: objects it
     * could not read, and interventions that stand on a building (each with the reason).
     *
     * @param list<array<string, mixed>> $objects
     *
     * @return array{ids: list<string>, rejected: int, conflicts: list<array<string, mixed>>}
     */
    public function add(string $planId, array $objects): array
    {
        $plan = $this->plans->get($planId);
        $normalizer = new SiteType();
        // objects from an import have no ids yet: give them theirs first, so only new ones count as new
        $current = SiteType::rawObjects($normalizer->normalize($plan->siteJson()));
        $known = array_column($current, O::ID);

        $additions = [];
        $conflicts = [];
        $site = Site::fromJson($normalizer->normalize(['objects' => $current]));
        foreach ($objects as $object) {
            unset($object[O::ID]);
            if (O::GREENROOF === ($object[O::TYPE] ?? null)) {
                try {
                    $object = self::greenRoof($site, $object);
                } catch (GreenRoofNotAllowed $e) {
                    $conflicts[] = [O::TYPE => O::GREENROOF, ...$e->toArray()];
                    continue;
                }
            }
            $additions[] = $object;
        }
        $result = SiteType::rawObjects($normalizer->normalize(['objects' => [...$current, ...$additions]]));
        $buildings = self::buildings($result);

        $kept = [];
        $ids = [];
        foreach ($result as $object) {
            $isNew = !in_array($object[O::ID], $known, true);
            $conflict = $isNew ? $this->clearance->conflict($object, $buildings) : null;
            if (null !== $conflict) {
                $conflicts[] = [O::TYPE => $object[O::TYPE], O::LABEL => $object[O::LABEL] ?? null, ...$conflict->toArray()];
                continue;
            }
            $kept[] = $object;
            if ($isNew) {
                $ids[] = (string) $object[O::ID];
            }
        }
        $this->save($plan, $normalizer->normalize(['objects' => $kept]));

        return ['ids' => $ids, 'rejected' => max(0, count($objects) - count($ids)), 'conflicts' => $conflicts];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @throws OverlapsBuilding     when the change moves an intervention onto a building (nothing is changed)
     * @throws GreenRoofNotAllowed when a green roof would become a kind its building cannot take
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
                // a roof chosen by hand is drawn as chosen, not as the 3D model has it
                if (O::BUILDING === ($object[O::TYPE] ?? null) && isset($changes[O::ROOF])) {
                    $changes[O::MODEL] = null;
                }
                // a green roof stays on its building, and becomes a roof garden only where that can carry one
                if (O::GREENROOF === ($object[O::TYPE] ?? null)) {
                    unset($changes[O::BUILDING_ID], $changes[O::POINTS]);
                    $kind = $changes[O::KIND] ?? null;
                    $assessment = GreenRoofs::assessIn(Site::fromJson((new SiteType())->normalize(['objects' => $objects])), (string) ($object[O::BUILDING_ID] ?? ''));
                    if (is_string($kind) && null !== $assessment && !$assessment->allows($kind)) {
                        throw new GreenRoofNotAllowed($assessment->buildingId, [sprintf('This roof takes no %s green roof.', $kind), ...$assessment->reasons]);
                    }
                }
                $objects[$i] = [...$object, ...$changes];
                $found = true;
            }
        }
        if (!$found) {
            return false;
        }
        $normalized = (new SiteType())->normalize(['objects' => $objects]);
        if ([] !== array_intersect(array_keys($changes), self::PLACEMENT)) {
            $result = SiteType::rawObjects($normalized);
            foreach ($result as $object) {
                $conflict = $id === $object[O::ID] ? $this->clearance->conflict($object, self::buildings($result)) : null;
                if (null !== $conflict) {
                    throw new OverlapsBuilding($conflict);
                }
            }
        }
        $this->save($plan, $normalized);

        return true;
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

    /**
     * Gives the plot (the analysed area) a new outline, as a person draws it on the plan.
     * The interventions stay where they are, inside the new outline or not.
     *
     * @param list<mixed> $points [x, z] in plan millimetres
     *
     * @return bool false when the plan has no plot or the outline is not one (too few or too many corners)
     */
    public function reshapePlot(string $planId, array $points): bool
    {
        $outline = Site::points([O::POINTS => $points]);
        if (count($outline) !== count($points) || count($outline) < self::minPlotCorners() || count($outline) > O::MAX_POINTS) {
            return false;
        }
        $plan = $this->plans->get($planId);
        $objects = SiteType::rawObjects($plan->siteJson());
        foreach ($objects as $i => $object) {
            if (is_array($object) && O::PLOT === ($object[O::TYPE] ?? null)) {
                // SiteType rounds and clamps the corners to the site's reach
                $objects[$i][O::POINTS] = $outline;
                $this->save($plan, (new SiteType())->normalize(['objects' => $objects]));

                return true;
            }
        }

        return false;
    }

    /** the fewest corners a plot's outline has */
    public static function minPlotCorners(): int
    {
        $field = O::fields(O::PLOT)[O::POINTS];

        return (int) $field[1];
    }

    public function replaceSite(string $planId, string $siteJson): void
    {
        $this->save($this->plans->get($planId), (new SiteType())->normalize($siteJson));
    }

    public function site(string $planId): Site
    {
        return Site::fromJson($this->plans->get($planId)->siteJson());
    }

    /**
     * A green roof as it is added: on a building of the plot that can take it (GreenRoofs), one per
     * building, its outline the building's footprint.
     *
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     *
     * @throws GreenRoofNotAllowed
     */
    private static function greenRoof(Site $site, array $object): array
    {
        $buildingId = is_string($object[O::BUILDING_ID] ?? null) ? $object[O::BUILDING_ID] : '';
        $assessment = GreenRoofs::assessIn($site, $buildingId);
        if (null === $assessment) {
            throw new GreenRoofNotAllowed($buildingId, [sprintf('No building "%s": give the id of a building from get_site.', $buildingId)]);
        }
        if (!$assessment->isEligible()) {
            throw new GreenRoofNotAllowed($buildingId, $assessment->reasons);
        }
        if (null !== GreenRoofs::onBuilding($site, $buildingId)) {
            throw new GreenRoofNotAllowed($buildingId, ['This building already has a green roof: change its kind with update_object instead.']);
        }
        $kind = is_string($object[O::KIND] ?? null) ? $object[O::KIND] : O::KIND_EXTENSIVE;
        if (!$assessment->allows($kind)) {
            throw new GreenRoofNotAllowed($buildingId, [sprintf('This roof takes no %s green roof.', $kind), ...$assessment->reasons]);
        }
        $building = GreenRoofs::buildingOf($site, $object);

        return [...$object, O::KIND => $kind, O::POINTS => null === $building ? [] : Site::points($building)];
    }

    /**
     * @param list<array<string, mixed>> $objects
     *
     * @return list<array<string, mixed>>
     */
    private static function buildings(array $objects): array
    {
        return array_values(array_filter($objects, static fn (array $object): bool => O::BUILDING === $object[O::TYPE]));
    }

    private function save(Plan $plan, string $siteJson): void
    {
        // whatever was added or moved stands on the ground there
        $siteJson = $this->grounding->ground($siteJson, $this->terrains->find($plan->id));
        $plan->replaceSite($siteJson);
        $this->plans->save($plan);
    }
}

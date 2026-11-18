<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Finds where one kind of intervention works on a plot, by the rules of that kind, and checks
 * that a candidate still fits when it is placed. One per intervention type, indexed by the type
 * (#[AsTaggedItem(index: SiteObject::…)]), looked up by Placements.
 */
#[AutoconfigureTag]
interface InterventionSiting
{
    public function propose(PlacementContext $context, PlacementRequest $request): Proposal;

    /**
     * @return string|null why the candidate no longer fits the plan (it changed since), or null
     */
    public function stillFits(PlacementContext $context, Candidate $candidate): ?string;
}

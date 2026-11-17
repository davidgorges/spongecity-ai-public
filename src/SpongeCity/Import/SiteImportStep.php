<?php

declare(strict_types=1);

namespace App\SpongeCity\Import;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One step of importing a plan's site. ImportSiteHandler runs every step in the order of
 * its priority (#[AsTaggedItem], highest first), each reading and adding to the context:
 *
 * 1. FetchOpenStreetMap: the map around the point
 * 2. ApplyOfficialLayers: a city's own buildings and trees in place of OpenStreetMap's
 * 3. BuildSite: the plot, buildings and trees as the configurator's site
 * 4. ImportTerrain: the ground the site stands on
 * 5. CollectSiteConditions: what official data says about the ground, with every source
 * 6. GroundSite: upright objects put onto the terrain
 *
 * When a step throws, the import fails.
 */
#[AutoconfigureTag]
interface SiteImportStep
{
    public function process(SiteImportContext $context): void;
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Import\Step;

use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Geo\Hamburg\HamburgInfiltration;
use App\SpongeCity\Import\ImportSite;
use App\SpongeCity\Import\SiteImportContext;
use App\SpongeCity\Import\SiteImportStep;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * What official data says about the ground (the infiltration potential), stored with the
 * sources of every layer of the site: the attribution and the agent read them there.
 */
#[AsTaggedItem(priority: 20)]
final readonly class CollectSiteConditions implements SiteImportStep
{
    public function __construct(
        private HamburgInfiltration $infiltration,
        private SiteConditionsStore $conditions,
    ) {
    }

    public function process(SiteImportContext $context): void
    {
        $this->conditions->save($context->planId, $this->infiltration->around($context->centre, ImportSite::CONDITIONS_RADIUS_METRES, $context->sources));
    }
}

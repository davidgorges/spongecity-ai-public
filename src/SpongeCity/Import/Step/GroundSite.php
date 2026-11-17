<?php

declare(strict_types=1);

namespace App\SpongeCity\Import\Step;

use App\SpongeCity\Import\SiteImportContext;
use App\SpongeCity\Import\SiteImportStep;
use App\SpongeCity\Terrain\SiteGrounding;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Buildings, trees and the plot put onto the terrain.
 */
#[AsTaggedItem(priority: 10)]
final readonly class GroundSite implements SiteImportStep
{
    public function __construct(
        private SiteGrounding $grounding,
    ) {
    }

    public function process(SiteImportContext $context): void
    {
        $context->siteJson = $this->grounding->ground($context->siteJson, $context->terrain);
    }
}

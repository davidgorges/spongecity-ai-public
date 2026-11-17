<?php

declare(strict_types=1);

namespace App\SpongeCity\Import\Step;

use App\SpongeCity\Geo\SiteImporter;
use App\SpongeCity\Import\SiteImportContext;
use App\SpongeCity\Import\SiteImportStep;
use Core\Configurator\Site\SiteType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The plot, buildings and trees as the configurator's site, normalised now, so every object
 * has its id from the start (the agent addresses buildings by it).
 */
#[AsTaggedItem(priority: 40)]
final readonly class BuildSite implements SiteImportStep
{
    public function __construct(
        private SiteImporter $importer,
        private SiteType $siteType,
    ) {
    }

    public function process(SiteImportContext $context): void
    {
        $context->siteJson = $this->siteType->normalize($this->importer->import($context->centre, $context->elements)->siteJson);
    }
}

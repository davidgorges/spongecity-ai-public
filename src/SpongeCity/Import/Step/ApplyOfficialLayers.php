<?php

declare(strict_types=1);

namespace App\SpongeCity\Import\Step;

use App\SpongeCity\Geo\MapArea;
use App\SpongeCity\Geo\MapLayers;
use App\SpongeCity\Import\SiteImportContext;
use App\SpongeCity\Import\SiteImportStep;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * A city's own buildings and street trees in place of OpenStreetMap's, where it has them.
 */
#[AsTaggedItem(priority: 50)]
final readonly class ApplyOfficialLayers implements SiteImportStep
{
    public function __construct(
        private MapLayers $layers,
    ) {
    }

    public function process(SiteImportContext $context): void
    {
        $map = $this->layers->apply($context->elements, MapArea::site($context->centre, $context->radiusMetres));
        $context->elements = $map->elements;
        $context->sources = [...$context->sources, ...$map->sources];
    }
}

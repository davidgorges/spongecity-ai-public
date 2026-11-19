<?php

declare(strict_types=1);

namespace App\SpongeCity\Import\Step;

use App\SpongeCity\Geo\OverpassClient;
use App\SpongeCity\Import\SiteImportContext;
use App\SpongeCity\Import\SiteImportStep;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The map around the point: plots, buildings, trees and roads from OpenStreetMap.
 */
#[AsTaggedItem(priority: 60)]
final readonly class FetchOpenStreetMap implements SiteImportStep
{
    public function __construct(
        private OverpassClient $overpass,
    ) {
    }

    public function process(SiteImportContext $context): void
    {
        $context->elements = $this->overpass->around($context->centre, $context->radiusMetres);
    }
}

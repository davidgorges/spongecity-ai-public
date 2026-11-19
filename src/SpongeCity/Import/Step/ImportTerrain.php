<?php

declare(strict_types=1);

namespace App\SpongeCity\Import\Step;

use App\SpongeCity\Geo\Hamburg\HamburgTerrain;
use App\SpongeCity\Import\SiteImportContext;
use App\SpongeCity\Import\SiteImportStep;
use App\SpongeCity\Terrain\TerrainStore;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The ground of the site from the terrain model: upright objects stand on it, the view
 * drapes the rest over it. None outside Hamburg: the ground stays flat.
 */
#[AsTaggedItem(priority: 30)]
final readonly class ImportTerrain implements SiteImportStep
{
    public function __construct(
        private HamburgTerrain $terrain,
        private TerrainStore $terrains,
    ) {
    }

    public function process(SiteImportContext $context): void
    {
        $context->terrain = $this->terrain->around($context->centre);
        $this->terrains->save($context->planId, $context->terrain);
        if (null !== $context->terrain) {
            $context->sources[] = $context->terrain->source;
        }
    }
}

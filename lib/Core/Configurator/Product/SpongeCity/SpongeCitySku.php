<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity;

final class SpongeCitySku
{
    public const string PLOT = 'SC-PLOT';
    public const string TREE = 'SC-TREE';
    public const string RAINGARDEN = 'SC-RAINGARDEN';
    public const string PERMEABLE_PAVERS = 'SC-PERMEABLE-PAVERS';
    public const string PERMEABLE_GRAVEL = 'SC-PERMEABLE-GRAVEL';
    public const string PERMEABLE_GRASS_PAVERS = 'SC-PERMEABLE-GRASS';
    public const string PLANTING = 'SC-PLANTING';
    public const string SHADESAIL = 'SC-SHADESAIL';
    public const string GREENROOF_EXTENSIVE = 'SC-GREENROOF-EXTENSIVE';
    public const string GREENROOF_INTENSIVE = 'SC-GREENROOF-INTENSIVE';
    /** per building with a green roof: structural survey, crane and scaffolding, site set-up */
    public const string GREENROOF_SETUP = 'SC-GREENROOF-SETUP';

    private function __construct()
    {
    }
}

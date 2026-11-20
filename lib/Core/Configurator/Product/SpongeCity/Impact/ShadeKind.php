<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

/**
 * What a patch of open ground lies under at the evaluated moment. A tree's shade feels cooler
 * than a building's or a sail's: the leaves evaporate and do not heat up like a wall or fabric.
 */
enum ShadeKind: string
{
    case Sun = 'sun';
    case Building = 'building';
    case Tree = 'tree';
    case Sail = 'sail';

    public function isShaded(): bool
    {
        return self::Sun !== $this;
    }
}

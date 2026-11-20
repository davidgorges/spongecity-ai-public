<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

/**
 * Where a value stands against its metric's target: met, on the way, or far off.
 */
enum Rating: string
{
    case Good = 'good';
    case Fair = 'fair';
    case Poor = 'poor';
}

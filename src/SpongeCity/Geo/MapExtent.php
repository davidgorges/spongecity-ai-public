<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

/**
 * What a map is read for: the site the agent plans on (detailed, every layer), or the city
 * around it the 3D view draws as context (lighter, buildings only).
 */
enum MapExtent
{
    case Site;
    case Surroundings;
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Terrain;

use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;

/**
 * Puts the objects of a site onto the terrain: each upright object gets the height of
 * the lowest ground under it (SiteObject::GROUND), every other object 0 (ground layers
 * follow the terrain in the view). Without a terrain everything stands at 0, as before.
 * Run whenever a site is saved, so objects an agent adds or moves stand right too.
 */
final readonly class SiteGrounding
{
    /**
     * @param string $siteJson a normalised site
     *
     * @return string the site with every object's ground set
     */
    public function ground(string $siteJson, ?Terrain $terrain): string
    {
        $objects = [];
        foreach (SiteType::rawObjects($siteJson) as $object) {
            if (!is_array($object)) {
                continue;
            }
            $upright = in_array($object[O::TYPE] ?? null, O::UPRIGHT, true);
            $object[O::GROUND] = null !== $terrain && $upright ? $terrain->groundOf($object) : 0;
            $objects[] = $object;
        }

        return SiteType::encode($objects);
    }
}

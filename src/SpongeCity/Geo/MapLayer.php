<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Official data that replaces one kind of OpenStreetMap element where it is better (a city's
 * cadastre for its buildings, its tree register for street trees). Works on Overpass-like
 * elements, so the importers stay unaware of where a building or tree came from.
 *
 * Layers are applied in the order of their priority (#[AsTaggedItem]); when one fails,
 * MapLayers keeps OpenStreetMap's elements of that kind.
 *
 * @phpstan-type Element array<string, mixed>
 */
#[AutoconfigureTag]
interface MapLayer
{
    /** whether the layer has data for the area (inside its city, for that extent) */
    public function covers(MapArea $area): bool;

    /**
     * @param list<Element> $elements
     *
     * @return list<Element> the elements with this layer's in place of OpenStreetMap's
     */
    public function apply(array $elements, MapArea $area): array;

    /** where the layer's data comes from, for the attribution and the agent */
    public function source(): DataSource;
}

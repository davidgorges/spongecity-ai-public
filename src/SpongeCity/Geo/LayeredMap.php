<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

/**
 * Map elements after the official layers were applied, with the sources of those layers.
 */
final readonly class LayeredMap
{
    /**
     * @param list<array<string, mixed>> $elements Overpass-like elements
     * @param list<DataSource>           $sources
     */
    public function __construct(
        public array $elements,
        public array $sources = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Applies every official layer that covers an area to OpenStreetMap's elements. Each layer
 * stands on its own: when its service fails, that kind of element stays as OpenStreetMap has it.
 */
final readonly class MapLayers
{
    /**
     * @param iterable<MapLayer> $layers
     */
    public function __construct(
        #[AutowireIterator(MapLayer::class)]
        private iterable $layers,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $elements raw Overpass elements
     */
    public function apply(array $elements, MapArea $area): LayeredMap
    {
        $sources = [];
        foreach ($this->layers as $layer) {
            if (!$layer->covers($area)) {
                continue;
            }
            try {
                $elements = $layer->apply($elements, $area);
                $sources[] = $layer->source();
            } catch (\Throwable $exception) {
                $this->logger->warning('Official map layer unavailable, keeping OpenStreetMap', ['layer' => $layer->source()->key, 'exception' => $exception]);
            }
        }

        return new LayeredMap($elements, $sources);
    }
}

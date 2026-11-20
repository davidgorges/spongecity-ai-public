<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\GreenRoof;

/**
 * One flat roof level a green roof can lie on: its outline, the planted part inside the gravel
 * strip, and how high its roof is above the building's ground.
 */
final readonly class RoofLevel
{
    /**
     * @param list<array{0: float, 1: float}> $points        the level's outline, plan millimetres
     * @param list<array{0: float, 1: float}> $plantedPoints the outline inside the gravel strip
     * @param float                           $height        millimetres above the building's ground (top of the walls)
     */
    public function __construct(
        public array $points,
        public array $plantedPoints,
        public float $height,
        public float $grossSquareMetres,
        public float $plantedSquareMetres,
    ) {
    }
}

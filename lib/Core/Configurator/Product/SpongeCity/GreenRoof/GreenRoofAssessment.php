<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\GreenRoof;

/**
 * Whether a building can take a green roof, which kinds, on which roof levels, and why: the
 * reasons and warnings are said to the user as they are.
 */
final readonly class GreenRoofAssessment
{
    /**
     * @param list<string>    $kinds    the kinds it can take (SiteObject::KIND_*), extensive first; empty: none
     * @param list<RoofLevel> $levels   the roof levels that would be planted
     * @param list<string>    $reasons  why it can, or why not
     * @param list<string>    $warnings what must be checked before it is built
     */
    public function __construct(
        public string $buildingId,
        public ?string $label,
        public array $kinds,
        public array $levels,
        public array $reasons,
        public array $warnings,
        public bool $isHall = false,
        /** false: a neighbour's building, not the plot's own */
        public bool $onPlot = true,
    ) {
    }

    public function isEligible(): bool
    {
        return [] !== $this->kinds && [] !== $this->levels;
    }

    public function allows(string $kind): bool
    {
        return in_array($kind, $this->kinds, true);
    }

    public function grossSquareMetres(): float
    {
        return array_sum(array_map(static fn (RoofLevel $level): float => $level->grossSquareMetres, $this->levels));
    }

    public function plantedSquareMetres(): float
    {
        return array_sum(array_map(static fn (RoofLevel $level): float => $level->plantedSquareMetres, $this->levels));
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

/**
 * An intervention that stands where a building stands, and which building: what the agent needs
 * to draw it again in the open.
 */
final readonly class BuildingConflict
{
    private const float SQUARE_MM_PER_SQUARE_METRE = 1_000_000.0;

    public function __construct(
        public string $buildingId,
        public ?string $buildingLabel,
        /** the area it covers of the building, in square millimetres; 0 for a tree's trunk inside it */
        public float $overlap,
    ) {
    }

    public function message(): string
    {
        $building = sprintf('building %s%s', $this->buildingId, null === $this->buildingLabel ? '' : sprintf(' ("%s")', $this->buildingLabel));

        return $this->overlap > 0
            ? sprintf('Overlaps %s by %.1f m²: draw the outline outside the building\'s outline (see existing_buildings in get_site).', $building, $this->overlap / self::SQUARE_MM_PER_SQUARE_METRE)
            : sprintf('Stands inside %s: place it outside the building\'s outline (see existing_buildings in get_site).', $building);
    }

    /**
     * @return array{building: string, building_label: string|null, overlap_m2: float, reason: string}
     */
    public function toArray(): array
    {
        return [
            'building' => $this->buildingId,
            'building_label' => $this->buildingLabel,
            'overlap_m2' => round($this->overlap / self::SQUARE_MM_PER_SQUARE_METRE, 1),
            'reason' => $this->message(),
        ];
    }
}

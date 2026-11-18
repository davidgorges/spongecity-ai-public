<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

/**
 * What the agent asks for: candidates for one kind of intervention, optionally of a size and
 * near a point the user named.
 */
final readonly class PlacementRequest
{
    public const int DEFAULT_COUNT = 3;
    public const int MAX_COUNT = 5;

    public int $count;

    public function __construct(
        public string $type,
        /** the wanted area; null: sized to what drains into it */
        public ?float $areaM2 = null,
        /** plan millimetres [x, z]: candidates near it come first, far ones are left out */
        public ?array $near = null,
        int $count = self::DEFAULT_COUNT,
    ) {
        $this->count = max(1, min(self::MAX_COUNT, $count));
    }
}

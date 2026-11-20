<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

/**
 * A green roof that its building cannot take (no flat roof, not on the plot, too small, no roof
 * garden on a hall), or a second one on the same building: nothing is changed.
 */
final class GreenRoofNotAllowed extends \DomainException
{
    /**
     * @param list<string> $reasons why not, as GreenRoofs says it
     */
    public function __construct(
        public readonly string $buildingId,
        public readonly array $reasons,
    ) {
        parent::__construct(implode(' ', $reasons));
    }

    /**
     * @return array{building: string, reasons: list<string>}
     */
    public function toArray(): array
    {
        return ['building' => $this->buildingId, 'reasons' => $this->reasons];
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

/**
 * An intervention was moved onto a building: the change is not made.
 */
final class OverlapsBuilding extends \DomainException
{
    public function __construct(
        public readonly BuildingConflict $conflict,
    ) {
        parent::__construct($conflict->message());
    }
}

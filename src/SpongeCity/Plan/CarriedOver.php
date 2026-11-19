<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

/**
 * What a person added to a plan's site, kept aside while it is imported again.
 */
final readonly class CarriedOver
{
    /**
     * @param list<array<string, mixed>> $interventions the planned interventions, and the areas whose use a person set
     * @param list<array<string, mixed>> $looks         buildings whose façade a person corrected
     */
    public function __construct(
        public array $interventions,
        public array $looks,
    ) {
    }
}

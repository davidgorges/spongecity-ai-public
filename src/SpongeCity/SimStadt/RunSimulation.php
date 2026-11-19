<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

/**
 * Asks a worker to run a SimStadt workflow on the buildings of a plan's site.
 */
final readonly class RunSimulation
{
    public function __construct(
        public string $planId,
        public SimStadtWorkflow $workflow,
        public int $yearOfConstruction,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

use Symfony\Component\Workflow\Attribute\Place;

/**
 * Where a SimStadt run stands: the places of the SimulationRun state machine.
 */
enum SimulationStatus: string
{
    #[Place(description: 'Waiting for the SimStadt worker', initial: true)]
    case Queued = 'queued';

    #[Place(description: 'SimStadt is running in its Docker image')]
    case Running = 'running';

    #[Place(description: 'The results are in')]
    case Done = 'done';

    #[Place(description: 'The run failed; the reason is kept for the agent')]
    case Failed = 'failed';

    /** queued or running: one run of a workflow at a time per plan */
    public function isActive(): bool
    {
        return self::Queued === $this || self::Running === $this;
    }
}

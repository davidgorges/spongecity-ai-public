<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

use Symfony\Component\Workflow\Attribute\AsEnteredListener;
use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Attribute\Transition;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\WorkflowTrait;

/**
 * A SimStadt run, as a state machine: queued for the worker, running, then done or failed.
 * Every status a run enters is stored at once: the agent polls it.
 */
#[AsWorkflow(
    name: self::NAME,
    supports: Simulation::class,
    markingProperty: 'status',
    description: 'A SimStadt building simulation for a plan',
    places: SimulationStatus::class,
)]
final class SimulationRun
{
    use WorkflowTrait;

    public const string NAME = 'simulation_run';

    #[Transition(from: SimulationStatus::Queued, to: SimulationStatus::Running, description: 'The worker takes up the run')]
    public const string START = 'start';

    #[Transition(from: SimulationStatus::Running, to: SimulationStatus::Done, description: 'The results are recorded')]
    public const string FINISH = 'finish';

    #[Transition(from: SimulationStatus::Queued, to: SimulationStatus::Failed, description: 'The plan is gone')]
    #[Transition(from: SimulationStatus::Running, to: SimulationStatus::Failed, description: 'No buildings, or SimStadt failed')]
    public const string FAIL = 'fail';

    public function __construct(
        private readonly SimulationStore $simulations,
    ) {
    }

    #[AsEnteredListener]
    public function store(EnteredEvent $event): void
    {
        $simulation = $event->getSubject();
        \assert($simulation instanceof Simulation);
        $this->simulations->save($simulation);
    }
}

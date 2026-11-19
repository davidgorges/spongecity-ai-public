<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use Symfony\Component\Workflow\Attribute\AsEnteredListener;
use Symfony\Component\Workflow\Attribute\AsWorkflow;
use Symfony\Component\Workflow\Attribute\Transition;
use Symfony\Component\Workflow\Event\EnteredEvent;
use Symfony\Component\Workflow\WorkflowTrait;

/**
 * The import of a plan's site, as a state machine: requested and queued (pending), taken up
 * by a worker (importing), then ready or failed. A failed or ready plan can be imported again.
 * Only REQUEST queues an import and only START begins one: whoever applies them holds the
 * plan's lock (LOCK_PREFIX), so a plan is queued once and imported by one worker.
 *
 * Every status a plan enters is stored at once: the page and the agent poll it.
 */
#[AsWorkflow(
    name: self::NAME,
    supports: Plan::class,
    markingProperty: 'status',
    description: 'The import of a plan\'s site from OpenStreetMap and official geodata',
    places: PlanStatus::class,
)]
final class PlanImport
{
    use WorkflowTrait;

    public const string NAME = 'plan_import';

    /** the lock held while a plan's import is requested or taken up, followed by the plan's id */
    public const string LOCK_PREFIX = 'plan_import_';

    #[Transition(from: PlanStatus::New, to: PlanStatus::Pending, description: 'Import the new plan\'s site')]
    #[Transition(from: PlanStatus::Ready, to: PlanStatus::Pending, description: 'Import the site again')]
    #[Transition(from: PlanStatus::Failed, to: PlanStatus::Pending, description: 'Try the import again')]
    public const string REQUEST = 'request';

    #[Transition(from: PlanStatus::Pending, to: PlanStatus::Importing, description: 'A worker takes up the import')]
    public const string START = 'start';

    #[Transition(from: PlanStatus::Importing, to: PlanStatus::Ready, description: 'The site is in place')]
    public const string COMPLETE = 'complete';

    #[Transition(from: PlanStatus::Pending, to: PlanStatus::Failed)]
    #[Transition(from: PlanStatus::Importing, to: PlanStatus::Failed, description: 'The map services failed')]
    public const string FAIL = 'fail';

    public function __construct(
        private readonly PlanStore $plans,
    ) {
    }

    #[AsEnteredListener(workflow: self::NAME)]
    public function store(EnteredEvent $event): void
    {
        $plan = $event->getSubject();
        \assert($plan instanceof Plan);
        $this->plans->save($plan);
    }
}

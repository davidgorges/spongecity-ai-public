<?php

declare(strict_types=1);

namespace App\SpongeCity\Import;

use App\SpongeCity\Geo\SurroundingsLoader;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanImport;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * Once a plan's site is in place, the real city around it for the 3D view. Only context:
 * when it fails the plan stays ready, and the surroundings are asked for again when shown.
 */
#[AsCompletedListener(workflow: PlanImport::NAME, transition: PlanImport::COMPLETE)]
final readonly class ImportSurroundings
{
    public function __construct(
        private SurroundingsLoader $surroundings,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $plan = $event->getSubject();
        \assert($plan instanceof Plan);
        $this->surroundings->import($plan);
    }
}

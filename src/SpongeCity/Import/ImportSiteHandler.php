<?php

declare(strict_types=1);

namespace App\SpongeCity\Import;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Plan\PlanImport;
use App\SpongeCity\Plan\PlanStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * Imports a plan's site in a worker: takes the plan up, runs every SiteImportStep, then
 * completes the import with the new site (or fails it, and the plan shows an error).
 */
#[AsMessageHandler]
final readonly class ImportSiteHandler
{
    /**
     * @param iterable<SiteImportStep> $steps
     */
    public function __construct(
        private PlanStore $plans,
        private PlanImport $import,
        #[AutowireIterator(SiteImportStep::class)]
        private iterable $steps,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ImportSite $message): void
    {
        $plan = $this->plans->find($message->planId)
            ?? throw new UnrecoverableMessageHandlingException(sprintf('Plan "%s" is gone.', $message->planId));
        if (!$this->import->can($plan, PlanImport::START)) {
            return; // not requested, or another worker has it
        }
        $this->import->apply($plan, PlanImport::START);

        $context = new SiteImportContext($plan->id, new GeoPoint($message->latitude, $message->longitude, $plan->title), $message->radiusMetres);
        try {
            foreach ($this->steps as $step) {
                $step->process($context);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Site import failed', ['plan' => $plan->id, 'step' => isset($step) ? $step::class : null, 'exception' => $exception]);
            $this->import->apply($plan, PlanImport::FAIL);

            throw $exception;
        }

        $plan->relocate($message->latitude, $message->longitude);
        $plan->replaceSite($context->siteJson);
        $this->import->apply($plan, PlanImport::COMPLETE);
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Import;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanImport;
use App\SpongeCity\Plan\PlanStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * Asks for a plan's site to be imported: a worker does it (the map services take a while),
 * or this process at once (the console). One import per plan at a time.
 */
final readonly class PlanImporter
{
    /** the transport that handles a message in the process that dispatches it */
    public const string TRANSPORT_NOW = 'sync';

    public function __construct(
        private PlanImport $import,
        private PlanStore $plans,
        private LockFactory $locks,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Queues the import of a new, ready or failed plan. The plan is read again under its lock,
     * so of two requests at once only the first queues it.
     *
     * @return bool false when the plan is already queued or being imported
     */
    public function request(Plan $plan, GeoPoint $centre, int $radiusMetres = ImportSite::DEFAULT_RADIUS_METRES, bool $now = false): bool
    {
        $lock = $this->locks->createLock(PlanImport::LOCK_PREFIX.$plan->id);
        $lock->acquire(true);
        try {
            $current = $this->plans->get($plan->id);
            if (!$this->import->can($current, PlanImport::REQUEST)) {
                return false;
            }
            $this->import->apply($current, PlanImport::REQUEST);
        } finally {
            // released before dispatching: a worker in this process (now) takes the same lock
            $lock->release();
        }

        $this->bus->dispatch(
            new ImportSite($plan->id, $centre->latitude, $centre->longitude, $radiusMetres),
            $now ? [new TransportNamesStamp(self::TRANSPORT_NOW)] : [],
        );

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Import;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanImport;
use App\SpongeCity\Plan\PlanStatus;
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
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @return bool false when the plan is already being imported
     */
    public function request(Plan $plan, GeoPoint $centre, int $radiusMetres = ImportSite::DEFAULT_RADIUS_METRES, bool $now = false): bool
    {
        // a new plan starts out pending; a ready or failed one is requested again, an importing one is left alone
        if (PlanStatus::Pending !== $plan->status) {
            if (!$this->import->can($plan, PlanImport::REQUEST)) {
                return false;
            }
            $this->import->apply($plan, PlanImport::REQUEST);
        }
        $this->bus->dispatch(
            new ImportSite($plan->id, $centre->latitude, $centre->longitude, $radiusMetres),
            $now ? [new TransportNamesStamp(self::TRANSPORT_NOW)] : [],
        );

        return true;
    }
}

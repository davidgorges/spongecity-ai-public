<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Plan;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Import\ImportSite;
use App\SpongeCity\Import\ImportSiteHandler;
use App\SpongeCity\Import\PlanImporter;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanImport;
use App\SpongeCity\Plan\PlanStatus;
use App\SpongeCity\Plan\PlanStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The import of a plan's site as a state machine: requested, taken up by one worker, stored at every step.
 */
final class PlanImportTest extends KernelTestCase
{
    private const float LATITUDE = 52.52;
    private const float LONGITUDE = 13.40;

    #[Test]
    public function aReadyPlanIsRequestedAgainAndQueuedForAWorker(): void
    {
        $plan = $this->plans()->create('Berlin', self::LATITUDE, self::LONGITUDE);

        self::assertTrue($this->importer()->request($plan, new GeoPoint(self::LATITUDE, self::LONGITUDE)));

        self::assertSame(PlanStatus::Pending, $this->plans()->get($plan->id)->status, 'stored as soon as it is entered');
        self::assertCount(1, $this->queue()->getSent());
        self::assertInstanceOf(ImportSite::class, $this->queue()->getSent()[0]->getMessage());
    }

    #[Test]
    public function aNewPlanIsQueuedAsItIs(): void
    {
        $plan = $this->plans()->create('Berlin', self::LATITUDE, self::LONGITUDE, PlanStatus::Pending);

        self::assertTrue($this->importer()->request($plan, new GeoPoint(self::LATITUDE, self::LONGITUDE)));
        self::assertCount(1, $this->queue()->getSent());
    }

    #[Test]
    public function aPlanBeingImportedIsNotRequestedTwice(): void
    {
        $plan = $this->plans()->create('Berlin', self::LATITUDE, self::LONGITUDE, PlanStatus::Importing);

        self::assertFalse($this->importer()->request($plan, new GeoPoint(self::LATITUDE, self::LONGITUDE)));
        self::assertSame([], $this->queue()->getSent());
    }

    #[Test]
    public function aWorkerLeavesAPlanAloneThatAnotherOneHasTakenUp(): void
    {
        $plan = $this->plans()->create('Berlin', self::LATITUDE, self::LONGITUDE, PlanStatus::Importing);

        static::getContainer()->get(ImportSiteHandler::class)(new ImportSite($plan->id, self::LATITUDE, self::LONGITUDE, ImportSite::DEFAULT_RADIUS_METRES));

        self::assertSame(PlanStatus::Importing, $this->plans()->get($plan->id)->status);
    }

    #[Test]
    public function aFailedImportCanBeTriedAgainButNotCompleted(): void
    {
        $import = static::getContainer()->get(PlanImport::class);
        $plan = new Plan('p', 'Berlin', self::LATITUDE, self::LONGITUDE, status: PlanStatus::Failed);

        self::assertTrue($import->can($plan, PlanImport::REQUEST));
        self::assertFalse($import->can($plan, PlanImport::COMPLETE));
        self::assertFalse($import->can($plan, PlanImport::START));
    }

    #[Test]
    public function plansStoredWithAStatusStringStillLoad(): void
    {
        // as PHP serialised a plan when it was readonly and its status a string
        $stored = 'O:24:"App\SpongeCity\Plan\Plan":6:{s:2:"id";s:1:"p";s:5:"title";s:6:"Berlin";s:8:"latitude";d:52.52;s:9:"longitude";d:13.4;s:6:"values";a:1:{s:5:"storm";i:40;}s:6:"status";s:5:"ready";}';

        $plan = unserialize($stored);

        self::assertInstanceOf(Plan::class, $plan);
        self::assertSame(PlanStatus::Ready, $plan->status);
        self::assertSame(40, $plan->storm());
        self::assertEquals($plan, unserialize(serialize($plan)));
    }

    private function plans(): PlanStore
    {
        return static::getContainer()->get(PlanStore::class);
    }

    private function importer(): PlanImporter
    {
        return static::getContainer()->get(PlanImporter::class);
    }

    private function queue(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        return $transport;
    }
}

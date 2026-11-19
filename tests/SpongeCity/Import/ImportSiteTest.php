<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Import;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\OverpassClient;
use App\SpongeCity\Geo\SurroundingsStore;
use App\SpongeCity\Import\ImportSite;
use App\SpongeCity\Import\ImportSiteHandler;
use App\SpongeCity\Import\PlanImporter;
use App\SpongeCity\Plan\PlanStatus;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A plan's site imported the way the worker does it: every step in order, the plan moved along
 * its state machine, the surroundings once it is ready. Outside Hamburg, so OpenStreetMap is all
 * there is (and the only service to stand in for).
 */
final class ImportSiteTest extends KernelTestCase
{
    private const float LATITUDE = 52.52;
    private const float LONGITUDE = 13.40;
    /** about 10 m in degrees of latitude here */
    private const float D = 0.00009;

    #[Test]
    public function importsTheSiteAndThenItsSurroundings(): void
    {
        $this->openStreetMap(new MockResponse((string) json_encode(['elements' => [$this->school()]])));
        $plan = $this->plans()->create('Berlin', self::LATITUDE, self::LONGITUDE, PlanStatus::Pending);

        $this->import($plan->id);

        $plan = $this->plans()->get($plan->id);
        self::assertSame(PlanStatus::Ready, $plan->status);
        self::assertCount(1, Site::fromJson($plan->siteJson())->ofType(O::BUILDING));
        self::assertNotNull(static::getContainer()->get(SurroundingsStore::class)->find($plan->id), 'imported once the plan is ready');
    }

    #[Test]
    public function aFailingMapServiceFailsThePlan(): void
    {
        $this->openStreetMap(new MockResponse('', ['http_code' => 503]));
        $plan = $this->plans()->create('Berlin', self::LATITUDE, self::LONGITUDE, PlanStatus::Pending);

        try {
            $this->import($plan->id);
            self::fail('the worker sees the failure');
        } catch (\Throwable) {
        }

        self::assertSame(PlanStatus::Failed, $this->plans()->get($plan->id)->status);
    }

    #[Test]
    public function theConsoleImportsAtOnce(): void
    {
        $this->openStreetMap(new MockResponse((string) json_encode(['elements' => [$this->school()]])));
        $plan = $this->plans()->create('Berlin', self::LATITUDE, self::LONGITUDE);

        static::getContainer()->get(PlanImporter::class)->request($plan, new GeoPoint(self::LATITUDE, self::LONGITUDE), now: true);

        self::assertSame(PlanStatus::Ready, $this->plans()->get($plan->id)->status);
    }

    private function import(string $planId): void
    {
        static::getContainer()->get(ImportSiteHandler::class)(new ImportSite($planId, self::LATITUDE, self::LONGITUDE, ImportSite::DEFAULT_RADIUS_METRES));
    }

    private function openStreetMap(MockResponse $response): void
    {
        // every mirror answers alike; a fresh cache, so nothing is left from an earlier test
        static::getContainer()->set(OverpassClient::class, new OverpassClient(new MockHttpClient(static fn (): MockResponse => clone $response), new ArrayAdapter()));
    }

    private function plans(): PlanStore
    {
        return static::getContainer()->get(PlanStore::class);
    }

    /**
     * @return array<string, mixed> a 20 × 20 m school building north-east of the point
     */
    private function school(): array
    {
        $ring = [[0, 0], [0, 2], [2, 2], [2, 0], [0, 0]];

        return ['type' => 'way', 'id' => 1, 'tags' => ['building' => 'school'], 'geometry' => array_map(
            static fn (array $c): array => ['lat' => self::LATITUDE + $c[0] * self::D, 'lon' => self::LONGITUDE + $c[1] * self::D * 1.6],
            $ring,
        )];
    }
}

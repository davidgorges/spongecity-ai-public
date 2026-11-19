<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\SimStadt;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\Hamburg\HamburgCityGml;
use App\SpongeCity\Geo\UtmPlanTransform;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\SimStadt\RunSimulation;
use App\SpongeCity\SimStadt\RunSimulationHandler;
use App\SpongeCity\SimStadt\SimStadtResults;
use App\SpongeCity\SimStadt\SimStadtRunner;
use App\SpongeCity\SimStadt\SimStadtTemplates;
use App\SpongeCity\SimStadt\SimStadtWorkflow;
use App\SpongeCity\SimStadt\Simulation;
use App\SpongeCity\SimStadt\SimulationStatus;
use App\SpongeCity\SimStadt\SimulationStore;
use App\SpongeCity\SimStadt\Template\HeatDemand;
use App\SpongeCity\SimStadt\Template\PvPotential;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SimStadtTest extends KernelTestCase
{
    private const float LATITUDE = 53.5513228;
    private const float LONGITUDE = 10.0187849;
    private const string FIXTURES = __DIR__.'/../../fixtures/simstadt';

    #[Test]
    public function readsTheSummaryAsSimstadtpyPrintsIt(): void
    {
        $summary = (new SimStadtResults())->summary("HeatDemand for site.gml\n\n  Number of buildings                 : 25\n  Specific Heating demand             : 157 kWh / (m² · a)\n\nOutput files:\n  /data/out/x.csv\n");

        self::assertSame(['Number of buildings' => '25', 'Specific Heating demand' => '157 kWh / (m² · a)'], $summary);
    }

    #[Test]
    public function condensesHeatDemandToBuildingsInPlanCoordinates(): void
    {
        $buildings = (new SimStadtResults())->buildings((string) file_get_contents(self::FIXTURES.'/heat_demand.csv'), new HeatDemand(), new GeoPoint(self::LATITUDE, self::LONGITUDE));

        self::assertCount(2, $buildings);
        self::assertSame('DEHHALKAJ0000zRr', $buildings[0]['id'], 'the largest demand first');
        self::assertSame(407587.0, $buildings[0]['heating_and_hot_water_kwh_per_a']);
        self::assertSame('administration', $buildings[0]['use']);
        // 53.55212 / 10.01856: 15 m west and 88.6 m north of the centre (0.000797° of latitude)
        self::assertEqualsWithDelta(-15000, $buildings[0]['x'], 500);
        self::assertEqualsWithDelta(-88600, $buildings[0]['z'], 500);
    }

    #[Test]
    public function addsUpRoofSurfacesPerBuilding(): void
    {
        $buildings = (new SimStadtResults())->buildings((string) file_get_contents(self::FIXTURES.'/pv_potential.csv'), new PvPotential(), new GeoPoint(self::LATITUDE, self::LONGITUDE));

        self::assertCount(1, $buildings);
        self::assertSame(47.5, $buildings[0]['pv_kwp'], '8.0 + 16.9 + 22.6 kWp');
        self::assertArrayHasKey('x', $buildings[0]);
    }

    #[Test]
    public function everyWorkflowHasItsTemplate(): void
    {
        $templates = static::getContainer()->get(SimStadtTemplates::class);

        self::assertSame(['HeatDemand', 'HeatDemandWithShadow', 'PV'], array_map(static fn (SimStadtWorkflow $w): string => $templates->for($w)->name(), SimStadtWorkflow::cases()));
    }

    #[Test]
    public function runsAWorkflowForAPlanAndStoresTheResult(): void
    {
        $container = static::getContainer();
        $plan = $container->get(PlanStore::class)->create('Brecht-Schule', self::LATITUDE, self::LONGITUDE);
        $simulations = $container->get(SimulationStore::class);
        $repository = sys_get_temp_dir().'/simstadt_test_'.bin2hex(random_bytes(4));
        // the model's tile from a fixture, SimStadt from a script that writes its results
        $container->set(HamburgCityGml::class, new HamburgCityGml(self::zipServer(self::tile()), new ArrayAdapter()));
        $container->set(SimStadtRunner::class, new SimStadtRunner('simstadt/simstadt:cli', '', self::FIXTURES.'/fake-docker.sh', 60, $repository));
        $handler = $container->get(RunSimulationHandler::class);

        $handler(new RunSimulation($plan->id, SimStadtWorkflow::HeatDemand, 1955));

        $simulation = $simulations->find($plan->id, SimStadtWorkflow::HeatDemand);
        self::assertNotNull($simulation);
        self::assertSame(SimulationStatus::Done, $simulation->status, (string) $simulation->error);
        self::assertSame('376000 kWh / a', $simulation->summary['Yearly Heating demand']);
        self::assertCount(2, $simulation->buildings);
        self::assertStringContainsString('1 buildings', $simulation->assumptions[0], 'the far building is left out');
        self::assertStringContainsString('Construction year 1955', $simulation->assumptions[1]);
        self::assertSame(['.', '..'], array_values(array_diff(scandir($repository) ?: [], ['.cache'])), 'the run cleans up after itself');
    }

    #[Test]
    public function aRepeatedMessageLeavesARunningSimulationAlone(): void
    {
        $container = static::getContainer();
        $plan = $container->get(PlanStore::class)->create('Brecht-Schule', self::LATITUDE, self::LONGITUDE);
        $simulations = $container->get(SimulationStore::class);
        $simulations->save(new Simulation($plan->id, SimStadtWorkflow::HeatDemand, 1955, status: SimulationStatus::Running));

        $container->get(RunSimulationHandler::class)(new RunSimulation($plan->id, SimStadtWorkflow::HeatDemand, 1955));

        self::assertSame(SimulationStatus::Running, $simulations->find($plan->id, SimStadtWorkflow::HeatDemand)?->status);
    }

    #[Test]
    public function cutsTheSiteOutOfTheBuildingModel(): void
    {
        $extract = (new HamburgCityGml(self::zipServer(self::tile()), new ArrayAdapter()))->extract(new GeoPoint(self::LATITUDE, self::LONGITUDE), 100, 1960);

        self::assertSame(['DEHHNEAR'], $extract['buildings']);
        self::assertStringContainsString('srsName="urn:adv:crs:ETRS89_UTM32*DE_DHHN2016_NH"', $extract['gml'], 'SimStadt locates the model by its envelope');
        self::assertStringContainsString("<bldg:yearOfConstruction>1960</bldg:yearOfConstruction>\n      <bldg:roofType>", $extract['gml']);
        self::assertStringNotContainsString('DEHHFAR', $extract['gml']);
        self::assertStringEndsWith("</core:CityModel>\n", $extract['gml']);
    }

    /** a LoD2 tile with one building 10 m from the site's centre and one 400 m away */
    private static function tile(): string
    {
        $transform = new UtmPlanTransform(new GeoPoint(self::LATITUDE, self::LONGITUDE));
        $building = static function (string $id, float $x, float $z) use ($transform): string {
            [$e, $n] = $transform->toUtm($x, $z);
            $ring = implode(' ', array_map(static fn (array $d): string => sprintf('%.3f %.3f 7.000', $e + $d[0], $n + $d[1]), [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]]));

            return "  <core:cityObjectMember>\n    <bldg:Building gml:id=\"{$id}\">\n      <bldg:function>31001_3021</bldg:function>\n      <bldg:roofType>1000</bldg:roofType>\n"
                ."      <bldg:lod2Solid><gml:posList srsDimension=\"3\">{$ring}</gml:posList></bldg:lod2Solid>\n    </bldg:Building>\n  </core:cityObjectMember>\n";
        };

        return "<?xml version=\"1.0\"?>\n<core:CityModel xmlns:core=\"http://www.opengis.net/citygml/1.0\">\n  <gml:boundedBy><gml:Envelope/></gml:boundedBy>\n"
            .$building('DEHHNEAR', 10, 10).$building('DEHHFAR', 400, 0)."</core:CityModel>\n";
    }

    /** serves a ZIP holding the tile at the site under its LoD2 name, by byte ranges */
    private static function zipServer(string $tile): MockHttpClient
    {
        [$e, $n] = (new UtmPlanTransform(new GeoPoint(self::LATITUDE, self::LONGITUDE)))->toUtm(0, 0);
        $path = (string) tempnam(sys_get_temp_dir(), 'lod2');
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString(sprintf('LoD2_32_%d_%d_1_HH.gml', intdiv((int) $e, 1000), intdiv((int) $n, 1000)), $tile);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return new MockHttpClient(static function (string $method, string $url, array $options) use ($bytes): MockResponse {
            if ('HEAD' === $method) {
                return new MockResponse('', ['response_headers' => ['content-length' => (string) strlen($bytes)]]);
            }
            preg_match('/Range: bytes=(\d+)-(\d+)/i', implode("\n", $options['headers'] ?? []), $m);

            return new MockResponse(substr($bytes, (int) $m[1], (int) $m[2] - (int) $m[1] + 1), ['http_code' => 206]);
        });
    }
}

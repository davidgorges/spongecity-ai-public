<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Plan\ConditionsController;
use App\Mcp\SpongeCityTools;
use App\SpongeCity\Conditions\SiteConditions;
use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Geo\Hamburg\HamburgGeodata;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Site\SiteObject as O;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ConditionsControllerTest extends WebTestCase
{
    #[Test]
    public function servesTheInfiltrationMapAndItsSources(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $plans = $container->get(PlanStore::class);
        $plan = $plans->create('Test', 53.5513, 10.0188);
        // a 20 m plot, half of it in an area where infiltration is unlikely
        $plan->replaceSite((string) json_encode(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => [[-10000, -10000], [10000, -10000], [10000, 10000], [-10000, 10000]]],
        ]]));
        $plans->save($plan);
        $container->get(SiteConditionsStore::class)->save($plan->id, new SiteConditions(
            [[SiteConditions::POINTS => [[-50.0, -50.0], [0.0, -50.0], [0.0, 50.0], [-50.0, 50.0]], SiteConditions::HOLES => [], SiteConditions::POTENTIAL => 'unlikely', SiteConditions::DEPTH => '0 - 1 m', SiteConditions::FACILITY => 'Versickerung unwahrscheinlich']],
            [HamburgGeodata::infiltrationSource()],
        ));

        $client->request('GET', $container->get('router')->generate(ConditionsController::ROUTE, ['id' => $plan->id]));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('unlikely', $data[SiteConditions::INFILTRATION][0][SiteConditions::POTENTIAL]);
        self::assertSame('Versickerungspotentialkarte Hamburg', $data[SiteConditions::SOURCES][0]['title']);

        // the agent's view of the same plan: shares of the plot, with what they mean
        $answer = $container->get(SpongeCityTools::class)->getSiteConditions($plan->id);
        self::assertSame('unlikely', $answer['infiltration'][0]['potential']);
        self::assertEqualsWithDelta(0.5, $answer['infiltration'][0]['share_of_plot'], 0.05);
        self::assertEqualsWithDelta(0.5, $answer['unmapped_share_of_plot'], 0.05);
    }

    #[Test]
    public function answersNotFoundForAnUnknownPlan(): void
    {
        $client = static::createClient();

        $client->request('GET', '/plan/nope/conditions');

        self::assertResponseStatusCodeSame(404);
    }
}

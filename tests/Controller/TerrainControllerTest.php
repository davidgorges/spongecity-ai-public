<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\Plan\TerrainController;
use App\SpongeCity\Geo\Hamburg\HamburgTerrain;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\Terrain\Terrain;
use App\SpongeCity\Terrain\TerrainGrid;
use App\SpongeCity\Terrain\TerrainStore;
use Core\Configurator\Site\SiteObject as O;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TerrainControllerTest extends WebTestCase
{
    #[Test]
    public function servesTheGroundAndStandsNewObjectsOnIt(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $plan = $container->get(PlanStore::class)->create('Test', 53.5513, 10.0188);
        // a level 2 m above the origin everywhere
        $grid = new TerrainGrid(-10.0, -10.0, 10.0, 3, 3, array_fill(0, 9, 200));
        $container->get(TerrainStore::class)->save($plan->id, new Terrain(5.0, $grid, $grid, HamburgTerrain::source()));

        $client->request('GET', $container->get('router')->generate(TerrainController::ROUTE, ['id' => $plan->id]));

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(5.0, (float) $data[Terrain::REFERENCE]);
        self::assertSame(array_fill(0, 9, 200), $data[Terrain::GRIDS][0][TerrainGrid::HEIGHTS]);

        // what the agent adds stands on that ground
        $editor = $container->get(SiteEditor::class);
        $editor->add($plan->id, [[O::TYPE => O::NEWTREE, O::X => 1000, O::Z => 1000]]);
        self::assertSame(2000, $editor->site($plan->id)->ofType(O::NEWTREE)[0][O::GROUND]);
    }

    #[Test]
    public function answersNoContentWithoutTerrain(): void
    {
        $client = static::createClient();
        $plan = static::getContainer()->get(PlanStore::class)->create('Flat', 52.52, 13.40);

        $client->request('GET', '/plan/'.$plan->id.'/terrain');

        self::assertResponseStatusCodeSame(204);
    }
}

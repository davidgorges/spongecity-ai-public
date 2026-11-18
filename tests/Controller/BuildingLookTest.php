<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Mcp\SpongeCityTools;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A building's look corrected through the agent's tool is marked as set by a person (and so kept on a re-import).
 */
final class BuildingLookTest extends KernelTestCase
{
    #[Test]
    public function marksALookSetByHand(): void
    {
        $container = static::getContainer();
        $plans = $container->get(PlanStore::class);
        $plan = $plans->create('Look', 52.52, 13.40);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::BUILDING, O::POINTS => [[0, 0], [10000, 0], [10000, 10000], [0, 10000]], O::HEIGHT => 20000, O::FACADE => 'ribbon'],
        ]]));
        $plans->save($plan);
        $tools = $container->get(SpongeCityTools::class);
        $building = $tools->getSite($plan->id)['existing_buildings'][0];
        self::assertSame('ribbon', $building[O::FACADE]);

        // as the agent sends it: the changes are a JSON object
        self::assertSame(['updated' => true], $tools->updateObject($plan->id, (string) $building['id'], [O::FACADE => 'render', O::COLOR => '#f4f2ec']));

        $updated = $container->get(SiteEditor::class)->site($plan->id)->ofType(O::BUILDING)[0];
        self::assertSame('render', $updated[O::FACADE]);
        self::assertSame(O::LOOK_USER, $updated[O::LOOK]);
    }

    #[Test]
    public function aRoofChosenByHandReplacesTheModelsRoof(): void
    {
        $container = static::getContainer();
        $plans = $container->get(PlanStore::class);
        $plan = $plans->create('Roof', 53.55, 10.02);
        $model = [O::MODEL_ROOFS => [[[0, 9000, 0], [10000, 9000, 0], [10000, 12000, 5000]]], O::MODEL_WALLS => []];
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::BUILDING, O::POINTS => [[0, 0], [10000, 0], [10000, 10000], [0, 10000]], O::HEIGHT => 9000, O::ROOF => 'monopitch', O::MODEL => $model],
        ]]));
        $plans->save($plan);
        $editor = $container->get(SiteEditor::class);
        $id = (string) $editor->site($plan->id)->ofType(O::BUILDING)[0][O::ID];

        self::assertTrue($editor->update($plan->id, $id, [O::COLOR => '#f4f2ec']));
        self::assertArrayHasKey(O::MODEL, $editor->site($plan->id)->ofType(O::BUILDING)[0], 'other changes keep the model');

        self::assertTrue($editor->update($plan->id, $id, [O::ROOF => 'hip']));
        $updated = $editor->site($plan->id)->ofType(O::BUILDING)[0];
        self::assertSame('hip', $updated[O::ROOF]);
        self::assertArrayNotHasKey(O::MODEL, $updated);
    }
}

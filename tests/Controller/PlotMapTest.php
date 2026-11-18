<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\Placement\Zone;
use App\Twig\Components\PlanWorkspace;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The plot map draws the plot over the buildings around it, and a plot drawn anew on it is saved
 * as the plan's analysed area; an outline that is none leaves the plot as it was.
 */
final class PlotMapTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const array PLOT = [[-10000, -10000], [10000, -10000], [10000, 10000], [-10000, 10000]];
    private const array BUILDING = [[12000, -5000], [20000, -5000], [20000, 5000], [12000, 5000]];

    #[Test]
    public function theMapShowsThePlotAndTheBuildings(): void
    {
        $plan = $this->plan();

        $component = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id]);

        self::assertStringContainsString('data-controller="plot-map"', (string) $component->render());
        $workspace = $component->component();
        self::assertInstanceOf(PlanWorkspace::class, $workspace);
        $data = $workspace->plotMap();
        self::assertEquals(self::PLOT, $data[PlanWorkspace::MAP_PLOT]);
        self::assertEquals([self::BUILDING], $data[PlanWorkspace::MAP_BUILDINGS]);
    }

    #[Test]
    public function theMapShowsThePlannedInterventionsAndTheTreesAroundThem(): void
    {
        $plan = $this->plan();
        $editor = static::getContainer()->get(SiteEditor::class);
        $garden = [[-8000, -8000], [-2000, -8000], [-2000, -4000], [-8000, -4000]];
        $editor->add($plan->id, [
            [O::TYPE => O::RAINGARDEN, O::LABEL => 'By the gym', O::POINTS => $garden],
            [O::TYPE => O::NEWTREE, O::X => 5000, O::Z => 5000, O::HEIGHT => 10000],
        ]);

        $component = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id]);
        $workspace = $component->component();
        self::assertInstanceOf(PlanWorkspace::class, $workspace);
        $data = $workspace->plotMap();

        [$rainGarden, $tree] = $data[PlanWorkspace::MAP_INTERVENTIONS];
        self::assertSame(O::RAINGARDEN, $rainGarden['type']);
        self::assertSame('By the gym', $rainGarden['label']);
        self::assertEquals($garden, $rainGarden['points']);
        self::assertSame(O::NEWTREE, $tree['type']);
        self::assertNull($tree['label'], 'the map names it by its kind');
        self::assertSame([5000.0, 5000.0, 2800.0], [$tree['x'], $tree['z'], $tree['r']], 'the mature crown the calculation assumes');
        self::assertSame([['x' => -15000.0, 'z' => 0.0, 'r' => 3000.0]], $data[PlanWorkspace::MAP_TREES], 'the recorded crown');
        self::assertStringContainsString('data-plot-map-interventions-value', (string) $component->render());
    }

    #[Test]
    public function aZoneClickedOnTheMapGetsTheUseThePersonSets(): void
    {
        $plan = $this->plan();
        $component = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id]);
        $workspace = $component->component();
        self::assertInstanceOf(PlanWorkspace::class, $workspace);
        $zone = $workspace->plotMap()[PlanWorkspace::MAP_ZONES][0];
        self::assertSame(Zone::ASSUMED, $zone['source']);

        // the map sets the selected zone; the panel offers its uses
        $component->set(PlanWorkspace::PROP_ZONE, $zone['id']);
        self::assertStringContainsString('aria-labelledby="zone-title"', (string) $component->render());
        $component->call(PlanWorkspace::ACTION_SET_ZONE_USE, ['zone' => $zone['id'], 'use' => O::AREA_PLAY]);

        $workspace = $component->component();
        self::assertInstanceOf(PlanWorkspace::class, $workspace);
        self::assertSame(O::AREA_PLAY, $workspace->selectedZone()[O::USE] ?? null, 'still selected, under its new id');
        self::assertSame(Zone::FROM_USER, $workspace->selectedZone()['source'] ?? null);
        $areas = static::getContainer()->get(SiteEditor::class)->site($plan->id)->ofType(O::AREA);
        self::assertCount(1, $areas);
        self::assertSame(O::LOOK_USER, $areas[0][O::LOOK]);
    }

    #[Test]
    public function playEquipmentMarkedOnTheMapIsSaidWhatItIsTurnedAndRemoved(): void
    {
        $plan = $this->plan();
        $component = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id]);

        // a click on the map marks it there; it is selected, its panel offers the kinds
        $component->call(PlanWorkspace::ACTION_ADD_EQUIPMENT, ['x' => 5000, 'z' => 3000]);
        $workspace = $component->component();
        self::assertInstanceOf(PlanWorkspace::class, $workspace);
        $item = $workspace->selectedEquipment();
        self::assertNotNull($item);
        self::assertSame(O::EQUIPMENT_OTHER, $item[O::KIND]);
        self::assertSame(O::LOOK_USER, $item['look']);
        self::assertStringContainsString('aria-labelledby="equipment-title"', (string) $component->render());

        $component->call(PlanWorkspace::ACTION_SET_EQUIPMENT_KIND, ['id' => $item['id'], 'kind' => O::EQUIPMENT_SWING]);
        $component->call(PlanWorkspace::ACTION_TURN_EQUIPMENT, ['id' => $item['id']]);
        $editor = static::getContainer()->get(SiteEditor::class);
        $swing = $editor->site($plan->id)->ofType(O::EQUIPMENT)[0];
        self::assertSame(O::EQUIPMENT_SWING, $swing[O::KIND]);
        self::assertSame(O::EQUIPMENT_SIZES[O::EQUIPMENT_SWING][1], $swing[O::DEPTH], 'a swing\'s fall zone');
        self::assertSame(PlanWorkspace::EQUIPMENT_TURN_STEP, $swing[O::TURN]);
        self::assertSame([5000, 3000], [$swing[O::X], $swing[O::Z]], 'where it was marked');

        $workspace = $component->component();
        self::assertInstanceOf(PlanWorkspace::class, $workspace);
        self::assertCount(1, $workspace->plotMap()[PlanWorkspace::MAP_EQUIPMENT]);
        self::assertCount(4, $workspace->plotMap()[PlanWorkspace::MAP_EQUIPMENT][0]['points'], 'its fall zone');

        $component->call(PlanWorkspace::ACTION_REMOVE_EQUIPMENT, ['id' => $item['id']]);
        self::assertSame([], $editor->site($plan->id)->ofType(O::EQUIPMENT));
    }

    #[Test]
    public function aPlotDrawnOnTheMapIsSaved(): void
    {
        $plan = $this->plan();
        $outline = [[-10000, -10000], [15000, -12000], [15000, 8000], [0, 14000], [-10000, 10000]];

        $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id])
            ->call(PlanWorkspace::ACTION_RESHAPE_PLOT, ['points' => $outline]);

        $site = static::getContainer()->get(SiteEditor::class)->site($plan->id);
        self::assertEquals($outline, Site::points($site->ofType(O::PLOT)[0]));
        self::assertCount(1, $site->ofType(O::BUILDING), 'the buildings stay');
        self::assertCount(1, $site->ofType(O::PLOT));
    }

    #[Test]
    public function anOutlineThatIsNoneLeavesThePlot(): void
    {
        $plan = $this->plan();
        $editor = static::getContainer()->get(SiteEditor::class);

        self::assertFalse($editor->reshapePlot($plan->id, [[0, 0], [1000, 0]]), 'too few corners');
        self::assertFalse($editor->reshapePlot($plan->id, array_fill(0, O::MAX_POINTS + 1, [0, 0])), 'too many corners');
        self::assertFalse($editor->reshapePlot($plan->id, [[0, 0], [1000, 'x'], [0, 1000]]), 'a corner that is no point');

        self::assertEquals(self::PLOT, Site::points($editor->site($plan->id)->ofType(O::PLOT)[0]));
    }

    private function plan(): Plan
    {
        static::createClient();
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Plot', 53.55, 10.0);
        // as the import writes it: without ids
        $plan->replaceSite(SiteType::encode([
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::BUILDING, O::POINTS => self::BUILDING, O::HEIGHT => 9000],
            [O::TYPE => O::TREE, O::X => -15000, O::Z => 0, O::HEIGHT => 12000, O::DIAMETER => 6000],
        ]));
        $plans->save($plan);

        return $plan;
    }
}

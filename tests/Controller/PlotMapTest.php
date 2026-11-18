<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
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
        ]));
        $plans->save($plan);

        return $plan;
    }
}

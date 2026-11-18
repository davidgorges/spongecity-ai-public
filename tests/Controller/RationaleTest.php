<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Mcp\PlacementTools;
use App\Mcp\SpongeCityTools;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\Rationale\Rationale;
use App\SpongeCity\Rationale\RationaleStore;
use App\Twig\Components\PlanWorkspace;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Why the agent placed an object, shown over it in the 3D view and in its details, with what else
 * it had in mind: an 80 × 60 m asphalt yard south of a school, its ground described as asphalt.
 */
final class RationaleTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const array PLOT = [[-40000, -30000], [40000, -30000], [40000, 30000], [-40000, 30000]];
    private const array SCHOOL = [[-35000, -25000], [-5000, -25000], [-5000, -10000], [-35000, -10000]];
    private const string WHY = 'Along the school, where the children run: the middle of the yard stays free to play.';

    #[Test]
    public function theAgentsReasonAndAlternativesAreKeptWithWhatItPlaced(): void
    {
        $plan = $this->sealedYard();
        $tools = static::getContainer()->get(PlacementTools::class);
        $proposal = $tools->proposePlacements($plan->id, O::PERMEABLE, count: 1);
        $band = $proposal['candidates'][0];
        self::assertSame('band', $band['metrics']['shape']);

        $placed = $tools->placeCandidate($plan->id, $band['id'], why: self::WHY);

        $rationale = static::getContainer()->get(RationaleStore::class)->of($plan->id)->for($placed['id'] ?? '');
        self::assertNotNull($rationale);
        self::assertSame(self::WHY, $rationale->why);
        self::assertSame(Rationale::BY_AGENT, $rationale->by);
        self::assertNotEmpty($rationale->reasons, 'the rules\' reasons come with it');
        self::assertSame($band['metrics']['alternatives'], array_map(static fn ($a): string => $a->id, $rationale->alternatives), 'the other shapes for the same zone');

        // the workspace: a badge over it, the reasons and the alternatives in its details
        $component = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id]);
        $workspace = $component->component();
        self::assertInstanceOf(PlanWorkspace::class, $workspace);
        self::assertSame([$placed['id']], array_column($workspace->markers(), 'id'));
        $component->set(PlanWorkspace::PROP_SELECTED, $placed['id']);
        $workspace = $component->component();
        self::assertInstanceOf(PlanWorkspace::class, $workspace);
        $shown = $workspace->selectedObject()['rationale'] ?? null;
        self::assertSame(self::WHY, $shown['why'] ?? null);
        self::assertCount(count($rationale->alternatives), $shown['alternatives'] ?? []);
        self::assertStringContainsString('aria-labelledby="rationale-title"', (string) $component->render());
    }

    #[Test]
    public function aPersonSwapsInAnAlternativeAndBackToTheAgentsChoice(): void
    {
        $plan = $this->sealedYard();
        $tools = static::getContainer()->get(PlacementTools::class);
        $band = $tools->proposePlacements($plan->id, O::PERMEABLE, count: 1)['candidates'][0];
        $placed = $tools->placeCandidate($plan->id, $band['id'], why: self::WHY)['id'] ?? '';
        $store = static::getContainer()->get(RationaleStore::class);
        $alternative = $store->of($plan->id)->for($placed)?->alternatives[0];
        self::assertNotNull($alternative);
        $component = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id]);

        $component->call(PlanWorkspace::ACTION_USE_ALTERNATIVE, ['id' => $placed, 'alternative' => $alternative->id]);

        $editor = static::getContainer()->get(SiteEditor::class);
        $paving = $editor->site($plan->id)->ofType(O::PERMEABLE);
        self::assertCount(1, $paving, 'replaced, not added');
        self::assertEquals(Site::points($alternative->object), Site::points($paving[0]));
        $swapped = $store->of($plan->id)->for((string) $paving[0][O::ID]);
        self::assertSame(Rationale::BY_USER, $swapped?->by);
        self::assertSame(self::WHY, $swapped->why, 'the agent\'s reasons stay to read');
        self::assertContains($band['id'], array_map(static fn ($a): string => $a->id, $swapped->alternatives), 'the agent\'s choice is one of the alternatives now');

        $component->call(PlanWorkspace::ACTION_USE_ALTERNATIVE, ['id' => (string) $paving[0][O::ID], 'alternative' => $band['id']]);

        $back = $editor->site($plan->id)->ofType(O::PERMEABLE);
        self::assertSame(Rationale::BY_AGENT, $store->of($plan->id)->for((string) $back[0][O::ID])?->by, 'the agent\'s choice again');
    }

    #[Test]
    public function anObjectDrawnByHandKeepsTheAgentsReasonButNotOnTheObject(): void
    {
        $plan = $this->sealedYard();

        $result = static::getContainer()->get(SpongeCityTools::class)->addObjects($plan->id, [
            [O::TYPE => O::NEWTREE, O::X => 10000, O::Z => 10000, O::HEIGHT => 9000, SpongeCityTools::WHY => 'Shade for the benches at noon.'],
            [O::TYPE => O::NEWTREE, O::X => 20000, O::Z => 10000, O::HEIGHT => 9000],
        ]);

        self::assertCount(2, $result['ids']);
        $rationales = static::getContainer()->get(RationaleStore::class)->of($plan->id);
        self::assertSame('Shade for the benches at noon.', $rationales->for($result['ids'][0])?->why);
        self::assertNull($rationales->for($result['ids'][1]), 'no reason given, none kept');
        $tree = static::getContainer()->get(SiteEditor::class)->site($plan->id)->ofType(O::NEWTREE)[0];
        self::assertArrayNotHasKey(SpongeCityTools::WHY, $tree);
    }

    private function sealedYard(): Plan
    {
        static::createClient();
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Schoolyard', 53.55, 10.02);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::BUILDING, O::LABEL => 'School', O::POINTS => self::SCHOOL, O::HEIGHT => 12000],
            [O::TYPE => O::TREE, O::X => -20000, O::Z => 15000, O::HEIGHT => 12000, O::DIAMETER => 8000],
        ]]));
        $plans->save($plan);
        $tools = static::getContainer()->get(PlacementTools::class);
        $yard = array_column($tools->getZones($plan->id)['zones'], null, O::USE)[O::AREA_YARD];
        self::assertTrue($tools->describeZone($plan->id, $yard['id'], surface: O::SURFACE_ASPHALT)['set']);

        return $plans->get($plan->id);
    }
}

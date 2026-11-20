<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\HomeController;
use App\Controller\Plan\ShowPlanController;
use App\Mcp\McpEndpoint;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\PlanUpdates;
use App\SpongeCity\Plan\SiteEditor;
use App\Twig\Components\PlanWorkspace;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * How the workspace is handled: every window can be resized by its corner handle and snaps
 * with guide lines, the view
 * marks what the mouse is over (the context menu finds the object for it), and a finger held
 * on the view opens the context menu. Measures are told apart in their list and shown when
 * picked there; removing them all is asked first; numbers read as the interface's language
 * writes them; the plans opened are listed on the home page. The page follows the plan's changes
 * made elsewhere, and tells how to connect one's own agent to it.
 */
final class WorkspaceViewTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const array PLOT = [[-10000, -10000], [10000, -10000], [10000, 10000], [-10000, 10000]];

    #[Test]
    public function everyWindowCanBeResizedByItsCornerHandle(): void
    {
        $crawler = $this->workspace()->crawler();

        $windows = $crawler->filter('[data-window-manager-target="window"]');
        self::assertGreaterThan(0, $windows->count());
        $windows->each(function ($window): void {
            $id = $window->attr('data-window');
            $handle = $window->filter('[data-window-resize]');
            self::assertCount(1, $handle, sprintf('window "%s" has one resize handle', $id));
            self::assertSame($id, $handle->attr('data-window-manager-id-param'));
            self::assertStringContainsString('window-manager#resize ', $handle->attr('data-action'));
            self::assertStringContainsString('window-manager#resizeByKey', $handle->attr('data-action'));
            self::assertStringContainsString('window-manager#resetSize', $handle->attr('data-action'));
            self::assertStringStartsWith('Resize ', (string) $handle->attr('aria-label'), 'named, not a translation key');
        });
    }

    #[Test]
    public function windowsSnapWithGuideLinesAndTheGripTellsHow(): void
    {
        $crawler = $this->workspace()->crawler();

        self::assertCount(1, $crawler->filter('[data-window-manager-target="guideX"][data-window-guide][hidden]'));
        self::assertCount(1, $crawler->filter('[data-window-manager-target="guideY"][data-window-guide][hidden]'));
        $grip = $crawler->filter('[data-window="chat"] [data-action="keydown->window-manager#nudge"]');
        self::assertCount(1, $grip);
        self::assertStringContainsString('Shift-drag', (string) $grip->attr('aria-label'), 'how to take a window out of its group');
    }

    #[Test]
    public function theViewMarksWhatTheMouseIsOverAndAHeldFingerOpensTheMenu(): void
    {
        $crawler = $this->workspace()->crawler();

        $canvas = $crawler->filter('[data-configurator-viewer-target="canvas"]');
        self::assertStringContainsString('pointermove->configurator-viewer#hover', $canvas->attr('data-action'));
        self::assertStringContainsString('pointercancel->configurator-viewer#cancelPress', $canvas->attr('data-action'), 'a pinch cancels a long press');
        self::assertStringContainsString('[-webkit-touch-callout:none]', $canvas->attr('class'), 'no callout of the browser over the long press');

        $menu = $crawler->filter('[data-controller~="plan-context-menu"]');
        self::assertStringContainsString('configurator:hover@window->plan-context-menu#hover', $menu->attr('data-action'));

        $view = $crawler->filter('[data-controller~="configurator-viewer"]');
        self::assertStringContainsString('plan-context-menu:hovered@window->configurator-viewer#showHovered', $view->attr('data-action'));
        self::assertStringNotContainsString('pointermove->', $view->attr('data-action'), 'moves are handled once, on the canvas');
    }

    #[Test]
    public function measuresAreToldApartAndShownWhenPickedFromTheList(): void
    {
        $plan = $this->plan();
        $editor = static::getContainer()->get(SiteEditor::class);
        $editor->add($plan->id, [
            [O::TYPE => O::NEWTREE, O::X => 5000, O::Z => 5000, O::HEIGHT => 9000],
            [O::TYPE => O::NEWTREE, O::X => -5000, O::Z => 5000, O::HEIGHT => 12000],
            [O::TYPE => O::RAINGARDEN, O::POINTS => [[-8000, -8000], [-2000, -8000], [-2000, -4000], [-8000, -4000]], O::STORAGE => 300],
        ]);
        $component = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id]);
        $workspace = $component->component();
        self::assertInstanceOf(PlanWorkspace::class, $workspace);

        self::assertSame(['Shade tree 1', 'Shade tree 2', 'Rain garden'], array_column($workspace->interventions(), 'name'), 'numbered where they share a name');
        self::assertSame(['9 m tall', '12 m tall', '24 m²'], array_column($workspace->interventions(), 'size'));

        $tree = $workspace->interventions()[1];
        $component->call(PlanWorkspace::ACTION_SELECT_OBJECT, ['id' => $tree['id']]);
        $workspace = $component->component();
        self::assertInstanceOf(PlanWorkspace::class, $workspace);
        $selected = $workspace->selectedObject();
        self::assertSame($tree['id'], $selected['id'] ?? null, 'picked in the list: selected');
        self::assertArrayHasKey(O::HEIGHT, $selected['fields']);
        self::assertNotContains('Height', array_column($selected['facts'], 0), 'the height shows once: as the choice');
        self::assertContains('Crown', array_column($selected['facts'], 0));

        $crawler = $component->render()->crawler();
        self::assertSame('Deselect', trim($crawler->filter('#object-title')->ancestors()->filter('[data-slot="card-header"], div')->first()->filter('button')->last()->text()));
        self::assertSame('true', $crawler->filter('[data-live-action-param="'.PlanWorkspace::ACTION_SELECT_OBJECT.'"][data-live-id-param="'.$tree['id'].'"]')->attr('aria-current'));
    }

    #[Test]
    public function removingAllMeasuresIsAskedFirst(): void
    {
        $plan = $this->plan();
        static::getContainer()->get(SiteEditor::class)->add($plan->id, [
            [O::TYPE => O::NEWTREE, O::X => 5000, O::Z => 5000, O::HEIGHT => 9000],
            [O::TYPE => O::NEWTREE, O::X => -5000, O::Z => 5000, O::HEIGHT => 9000],
        ]);

        $crawler = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id])->render()->crawler();

        $clear = '[data-live-action-param="'.PlanWorkspace::ACTION_CLEAR.'"]';
        $dialog = $crawler->filter('[data-controller~="alert-dialog"]');
        self::assertCount(1, $dialog);
        self::assertStringContainsString('alert-dialog#open', $dialog->filter('[data-alert-dialog-target="trigger"]')->attr('data-action'), 'the button opens the question');
        self::assertCount(1, $crawler->filter($clear), 'only the confirmation removes');
        self::assertCount(1, $dialog->filter('dialog '.$clear));
        self::assertSame('Remove all 2 planned measures?', trim($dialog->filter('dialog [id$="-title"], dialog h2')->first()->text()));
    }

    #[Test]
    public function numbersAreWrittenAsTheInterfacesLanguageWritesThem(): void
    {
        $plan = $this->plan();
        $editor = static::getContainer()->get(SiteEditor::class);
        $editor->add($plan->id, [
            [O::TYPE => O::PERMEABLE, O::POINTS => [[-9000, -9000], [9000, -9000], [9000, 9000], [-9000, 9000]], O::KIND => O::KIND_PAVERS],
        ]);

        $html = (string) $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id])->render();

        self::assertMatchesRegularExpression('/\d,\d{3} €/', $html, 'thousands with a comma');
        self::assertDoesNotMatchRegularExpression('/\d\.\d{3} €/', $html);
        self::assertStringContainsString('400 m²', $html, 'the plot, 20 × 20 m');
        self::assertMatchesRegularExpression('/was \d+\.\d %/', $html, 'today\'s value, a decimal point');
        self::assertMatchesRegularExpression('/\+\d+\.\d pts/', $html, 'a share changes by percentage points');
    }

    #[Test]
    public function thePlansOpenedAreListedOnTheHomePage(): void
    {
        $plan = $this->plan();
        $client = static::getClient();
        $url = static::getContainer()->get('router')->generate(ShowPlanController::ROUTE, ['id' => $plan->id]);

        $page = $client->request('GET', $url);
        $recorder = $page->filter('[data-controller~="recent-plans"]');
        self::assertCount(1, $recorder);
        self::assertSame(HomeController::RECENT_PLANS_STORAGE_KEY, $recorder->attr('data-recent-plans-storage-key-value'));
        self::assertSame(['id' => $plan->id, 'title' => $plan->title, 'url' => $url], json_decode((string) $recorder->attr('data-recent-plans-plan-value'), true));

        $home = $client->request('GET', '/');
        $list = $home->filter('section[data-controller~="recent-plans"]');
        self::assertCount(1, $list, 'the list, filled in this browser');
        self::assertNotNull($list->attr('hidden'), 'hidden until there is a plan in it');
        self::assertCount(1, $list->filter('template[data-recent-plans-target="template"]'));
    }

    #[Test]
    public function thePageFollowsThePlanAndOffersToConnectOnesOwnAgent(): void
    {
        $plan = $this->plan();
        $client = static::getClient();
        $page = $client->request('GET', $client->getContainer()->get('router')->generate(ShowPlanController::ROUTE, ['id' => $plan->id]));

        $updates = $page->filter('[data-controller="plan-updates"]');
        self::assertCount(1, $updates);
        self::assertStringContainsString(rawurlencode(PlanUpdates::topic($plan->id)), (string) $updates->attr('data-plan-updates-stream-value'));
        self::assertSame(PlanWorkspace::EVENT_PLAN_CHANGED, $updates->attr('data-plan-updates-event-value'));

        $agent = $page->filter('[data-window="agent"]');
        self::assertCount(1, $agent);
        $mcpUrl = $client->getContainer()->get('router')->generate(McpEndpoint::ROUTE, [], UrlGeneratorInterface::ABSOLUTE_URL);
        self::assertSame($mcpUrl, $agent->filter('#agent-mcp-url')->attr('value'));
        self::assertSame($plan->id, $agent->filter('#agent-plan-id')->attr('value'));
        self::assertStringEndsWith(' '.$mcpUrl, (string) $agent->filter('#agent-claude-code')->attr('value'));
    }

    private function workspace(): \Symfony\UX\TwigComponent\Test\RenderedComponent
    {
        return $this->createLiveComponent(PlanWorkspace::class, ['planId' => $this->plan()->id])->render();
    }

    private function plan(): Plan
    {
        static::createClient();
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Plot', 53.55, 10.0);
        $plan->replaceSite(SiteType::encode([
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
        ]));
        $plans->save($plan);

        return $plan;
    }
}

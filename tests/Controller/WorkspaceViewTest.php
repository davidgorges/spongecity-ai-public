<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\Twig\Components\PlanWorkspace;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * How the workspace is handled: every window can be resized by its corner handle, the view
 * marks what the mouse is over (the context menu finds the object for it), and a finger held
 * on the view opens the context menu.
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

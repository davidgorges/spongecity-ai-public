<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\Schematics;
use App\SpongeCity\Plan\SiteEditor;
use App\Twig\Components\PlanWorkspace;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The object panel shows how the selected measure is built: a schematic per type and variant.
 */
final class SchematicsTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    #[Test]
    public function everyInterventionAndVariantHasItsImage(): void
    {
        $names = Schematics::all();

        self::assertCount(count(array_unique($names)), $names);
        foreach ($names as $name) {
            $file = \dirname(__DIR__, 2).'/assets/'.Schematics::path($name);
            self::assertFileExists($file);
            [$width, $height] = (array) getimagesize($file);
            self::assertSame([Schematics::WIDTH, Schematics::HEIGHT], [$width, $height], $name);
        }
        self::assertNull(Schematics::name([O::TYPE => O::BUILDING]), 'the city around has none');
    }

    #[Test]
    public function theSelectedMeasureShowsTheSchematicOfItsVariant(): void
    {
        $plan = $this->plan();
        $ids = static::getContainer()->get(SiteEditor::class)->add($plan->id, [
            [O::TYPE => O::RAINGARDEN, O::POINTS => [[-8000, -8000], [-2000, -8000], [-2000, -4000], [-8000, -4000]], O::STORAGE => 300, O::TRENCH => 800],
            [O::TYPE => O::PERMEABLE, O::POINTS => [[0, 0], [6000, 0], [6000, 6000], [0, 6000]], O::KIND => O::KIND_GRASS_PAVERS],
        ])['ids'];
        $component = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id]);

        $component->call(PlanWorkspace::ACTION_SELECT_OBJECT, ['id' => $ids[0]]);
        $image = $component->render()->crawler()->filter('[data-window="details"] img[src*="schematics/"]');
        self::assertCount(1, $image);
        self::assertStringContainsString('raingarden-trench', (string) $image->attr('src'));
        self::assertSame('Cutaway showing how it is built: Rain garden', $image->attr('alt'));
        // at most 256 px wide; beside the facts once the window is wide enough (a container query on the card)
        self::assertContains('max-w-64', explode(' ', (string) $image->attr('class')));
        $columns = $image->closest('div');
        self::assertNotNull($columns);
        self::assertStringContainsString('@md/object:grid-cols-[minmax(0,16rem)_minmax(0,1fr)]', (string) $columns->attr('class'));
        self::assertStringContainsString('@container/object', (string) $columns->ancestors()->first()->attr('class'));

        $component->call(PlanWorkspace::ACTION_SELECT_OBJECT, ['id' => $ids[1]]);
        self::assertStringContainsString('permeable-grass-pavers', (string) $component->render()->crawler()->filter('[data-window="details"] img[src*="schematics/"]')->attr('src'));
    }

    private function plan(): Plan
    {
        static::createClient();
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Plot', 53.55, 10.0);
        $plan->replaceSite(SiteType::encode([
            [O::TYPE => O::PLOT, O::POINTS => [[-10000, -10000], [10000, -10000], [10000, 10000], [-10000, 10000]], O::SURFACE => O::SURFACE_ASPHALT],
        ]));
        $plans->save($plan);

        return $plan;
    }
}

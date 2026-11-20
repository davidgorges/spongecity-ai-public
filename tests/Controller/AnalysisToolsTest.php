<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Mcp\AnalysisTools;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\Plan\ValueForMoney as V;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator as I;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Weighing options as the agent does: what each measure buys per euro, and scenarios to compare and go back to.
 */
final class AnalysisToolsTest extends KernelTestCase
{
    #[Test]
    public function pricesTheEffectOfEveryMeasureAndKind(): void
    {
        $planId = $this->plan();

        $value = static::getContainer()->get(AnalysisTools::class)->compareMeasures($planId);

        self::assertCount(4, $value['measures']);
        $byType = array_column($value['by_type'], null, 'type');
        self::assertSame(2, $byType[O::NEWTREE]['count']);
        self::assertGreaterThan(0.0, $byType[O::NEWTREE]['effect'][V::SHADE]);
        self::assertGreaterThan(0.0, $byType[O::RAINGARDEN]['effect'][V::RETAINED]);
        self::assertSame(0.0, $byType[O::RAINGARDEN]['effect'][V::SHADE], 'a rain garden casts no shade');
        self::assertNull($byType[O::RAINGARDEN]['cost_per_unit']['eur_per_'.V::SHADE]);

        $garden = array_find($value['measures'], static fn (array $m): bool => O::RAINGARDEN === $m['type']);
        self::assertEqualsWithDelta($garden['investment'] / $garden['effect'][V::RETAINED], $garden['cost_per_unit']['eur_per_'.V::RETAINED], 1.0);
        self::assertGreaterThan($garden['cost_per_unit']['eur_per_'.V::RETAINED], $garden['cost_per_unit']['lifecycle_eur_per_'.V::RETAINED], 'running costs make it dearer over the years');
        self::assertSame(O::RAINGARDEN, $value['measures'][0]['type'], 'the best value for water first');
        self::assertStringContainsString('count once', $value['basis']);
    }

    #[Test]
    public function savesComparesAndRestoresScenarios(): void
    {
        $container = static::getContainer();
        $tools = $container->get(AnalysisTools::class);
        $planId = $this->plan();
        $treeIds = array_column(Site::fromJson($container->get(PlanStore::class)->get($planId)->siteJson())->ofType(O::NEWTREE), O::ID);

        $full = $tools->saveScenario($planId, '  With   trees ');
        self::assertSame('With trees', $full['name']);
        self::assertSame(2, $full['measures'][O::NEWTREE]);
        foreach ($treeIds as $id) {
            $container->get(SiteEditor::class)->remove($planId, $id);
        }
        $tools->saveScenario($planId, 'Rain gardens only');

        $rows = array_column($tools->listScenarios($planId)['scenarios'], null, 'name');
        self::assertSame([AnalysisTools::CURRENT, 'With trees', 'Rain gardens only'], array_keys($rows));
        self::assertSame($rows['Rain gardens only']['balance'], $rows[AnalysisTools::CURRENT]['balance']);
        self::assertGreaterThan($rows['Rain gardens only']['balance'][I::METRIC_SHADE], $rows['With trees']['balance'][I::METRIC_SHADE]);
        self::assertGreaterThan($rows['Rain gardens only']['cost']['investment'], $rows['With trees']['cost']['investment']);

        self::assertSame(['restored' => 'With trees', 'interventions' => 4], $tools->restoreScenario($planId, 'With trees'));
        $restored = array_column(Site::fromJson($container->get(PlanStore::class)->get($planId)->siteJson())->ofType(O::NEWTREE), O::ID);
        self::assertSame($treeIds, $restored, 'the trees are back with their ids, and what was said about them');

        self::assertTrue($tools->deleteScenario($planId, 'Rain gardens only'));
        self::assertFalse($tools->deleteScenario($planId, 'Rain gardens only'));
        self::assertArrayHasKey('error', $tools->restoreScenario($planId, 'Rain gardens only'));
        self::assertArrayHasKey('error', $tools->saveScenario($planId, AnalysisTools::CURRENT));
    }

    private function plan(): string
    {
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Schulhof', 53.5513, 10.0188);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => [[-25000, -25000], [25000, -25000], [25000, 25000], [-25000, 25000]], O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::NEWTREE, O::X => -10000, O::Z => 10000, O::HEIGHT => 12000],
            [O::TYPE => O::NEWTREE, O::X => 10000, O::Z => 10000, O::HEIGHT => 12000],
            [O::TYPE => O::RAINGARDEN, O::POINTS => [[-15000, -15000], [-7000, -15000], [-7000, -10000], [-15000, -10000]]],
            [O::TYPE => O::PERMEABLE, O::KIND => O::KIND_PAVERS, O::POINTS => [[0, -20000], [20000, -20000], [20000, -5000], [0, -5000]]],
        ]]));
        $plans->save($plan);

        return $plan->id;
    }
}

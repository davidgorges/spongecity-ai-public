<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanAnalyzer;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\Schematics;
use App\Twig\Components\PlanWorkspace;
use Core\Configurator\Product\SpongeCity\SpongeCitySku as Sku;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Three rain gardens of three sizes are three lines of the bill (one per size), but one item for a
 * person: their count, area and cost added up. The measures window lists each measure once, with a
 * picture of how it is built, and the bill once per item.
 */
final class CostItemsTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    #[Test]
    public function theBillsLinesAreAddedUpPerItem(): void
    {
        $cost = static::getContainer()->get(PlanAnalyzer::class)->cost($this->plan());

        $gardenLines = array_values(array_filter($cost['lines'], static fn (array $line): bool => Sku::RAINGARDEN === $line['sku']));
        self::assertCount(3, $gardenLines, 'one line per size');
        $items = array_column($cost['items'], null, 'sku');
        self::assertSame(array_values(array_unique(array_column($cost['lines'], 'sku'))), array_column($cost['items'], 'sku'), 'one item per SKU, in the bill\'s order');
        self::assertSame(3, $items[Sku::RAINGARDEN]['count']);
        // 50 + 30 + 20 m²
        self::assertEqualsWithDelta(100.0, $items[Sku::RAINGARDEN]['amount'], 0.1);
        self::assertEqualsWithDelta(array_sum(array_column($gardenLines, 'total')), $items[Sku::RAINGARDEN]['total'], 0.01);
        self::assertEqualsWithDelta($cost['total'], array_sum(array_column($cost['items'], 'total')), 0.01, 'the items add up to the total');
        self::assertEqualsWithDelta(2.0, $items[Sku::TREE]['amount'], 0.01);
    }

    #[Test]
    public function theWindowListsEachMeasureWithItsPictureAndEachItemOnce(): void
    {
        $plan = $this->plan();
        $crawler = $this->createLiveComponent(PlanWorkspace::class, ['planId' => $plan->id])->render()->crawler();
        $window = $crawler->filter('[data-window="measures"]');

        $rows = $window->filter('[aria-labelledby="measures-list-title"] [role="listitem"]');
        self::assertCount(5, $rows, 'three rain gardens and two trees');
        $rows->each(static function ($row): void {
            self::assertStringContainsString(Schematics::DIRECTORY, (string) $row->filter('img')->attr('src'), 'a thumbnail of how it is built');
        });

        $bill = $window->filter('[aria-labelledby="bill-title"] tbody tr');
        self::assertCount(2, $bill, 'rain gardens and trees, once each');
        $gardens = $bill->reduce(static fn ($row): bool => str_contains($row->text(), 'Rain gardens'));
        self::assertCount(1, $gardens);
        self::assertStringContainsString('3 measures', $gardens->text());
        self::assertStringContainsString('100.0 m²', $gardens->text());
    }

    private function plan(): Plan
    {
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Schulhof', 53.5513, 10.0188);
        $garden = static fn (int $x, int $width): array => [O::TYPE => O::RAINGARDEN, O::POINTS => [[$x, 10000], [$x + $width, 10000], [$x + $width, 15000], [$x, 15000]]];
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => [[-30000, -30000], [30000, -30000], [30000, 30000], [-30000, 30000]], O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::NEWTREE, O::X => -5000, O::Z => 0, O::HEIGHT => 12000],
            [O::TYPE => O::NEWTREE, O::X => 5000, O::Z => 0, O::HEIGHT => 12000],
            // 10 m × 5 m, 6 m × 5 m, 4 m × 5 m
            $garden(-25000, 10000),
            $garden(-10000, 6000),
            $garden(5000, 4000),
        ]]));
        $plans->save($plan);

        return $plan;
    }
}

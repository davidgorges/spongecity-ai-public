<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Placement;

use App\Mcp\PlacementTools;
use App\SpongeCity\Placement\PermeableSiting;
use App\SpongeCity\Placement\Zone;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteCarryOver;
use App\SpongeCity\Plan\SiteEditor;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Permeable surfaces as a landscape architect plans them: the plot read as zones, each zone's use
 * deciding its material or that it stays, the shapes following the site's lines. An 80 × 60 m
 * asphalt schoolyard with a school, a car park and a playground on the map, and a street tree.
 */
final class PermeableSitingTest extends KernelTestCase
{
    private const array PLOT = [[-40000, -30000], [40000, -30000], [40000, 30000], [-40000, 30000]];
    /** 30 × 15 m in the north-west, its south façade at z = -10 m */
    private const array SCHOOL = [[-35000, -25000], [-5000, -25000], [-5000, -10000], [-35000, -10000]];
    private const array CAR_PARK = [[15000, -28000], [38000, -28000], [38000, -12000], [15000, -12000]];
    private const array PLAYGROUND = [[15000, 8000], [38000, 8000], [38000, 28000], [15000, 28000]];
    private const array TREE = [-20000, 15000];

    #[Test]
    public function thePlotIsReadAsZones(): void
    {
        $zones = $this->tools()->getZones($this->plan()->id)['zones'];

        $byUse = array_column($zones, null, O::USE);
        self::assertSame(Zone::FROM_MAP, $byUse[O::AREA_PARKING]['source']);
        self::assertSame(Zone::FROM_MAP, $byUse[O::AREA_PLAY]['source']);
        self::assertSame(Zone::ASSUMED, $byUse[O::AREA_YARD]['source'], 'the rest of the open ground is taken as a yard');
        self::assertStringStartsWith('Yard south', $byUse[O::AREA_YARD][O::LABEL], 'named by where it lies from the school');
        self::assertSame(O::SURFACE_UNKNOWN, $byUse[O::AREA_YARD][O::SURFACE], 'nobody said what its ground is: it may be lawn');
        self::assertGreaterThan(2500, $byUse[O::AREA_YARD]['area_m2']);
    }

    #[Test]
    public function groundNobodyHasDescribedIsNeverPaved(): void
    {
        $proposal = $this->tools()->proposePlacements($this->plan()->id, O::PERMEABLE, count: 5);

        self::assertSame([], array_values(array_filter($proposal['candidates'], static fn (array $c): bool => O::AREA_YARD === $c['metrics']['zone_use'])), 'it may be a garden');
        self::assertArrayHasKey(PermeableSiting::UNKNOWN_GROUND, $proposal['excluded_share_of_plot']);
        self::assertNotEmpty($proposal['candidates'], 'the car park is known to be asphalt');
    }

    #[Test]
    public function eachCandidateSaysWhyItsMaterialBeatTheOthersAndWhatThatCosts(): void
    {
        $proposal = $this->tools()->proposePlacements($this->sealedYard()->id, O::PERMEABLE, count: 5);
        $byKindAndUse = [];
        foreach ($proposal['candidates'] as $candidate) {
            $byKindAndUse[$candidate['metrics']['zone_use'].'/'.$candidate['metrics']['shape']] ??= $candidate;
        }

        $yard = $byKindAndUse[O::AREA_YARD.'/band'];
        self::assertEquals(PermeableSiting::WHY_NOT[O::AREA_YARD], $yard['metrics']['kinds_not_chosen']);
        $tradeOff = implode(' ', $yard['reasons']);
        self::assertStringContainsString('Pavers rather than grass pavers: '.PermeableSiting::WHY_NOT[O::AREA_YARD][O::KIND_GRASS_PAVERS], $tradeOff);
        self::assertMatchesRegularExpression('/Grass pavers would take about [\d.]+ m³ more off the runoff and be 8 K cooler/', $tradeOff, 'the price of the choice, in numbers');

        $carPark = array_values(array_filter($proposal['candidates'], static fn (array $c): bool => O::AREA_PARKING === $c['metrics']['zone_use']))[0];
        self::assertSame(O::KIND_GRASS_PAVERS, $carPark['metrics']['kind']);
        self::assertArrayHasKey(O::KIND_PAVERS, $carPark['metrics']['kinds_not_chosen']);
    }

    #[Test]
    public function aYardOffersABandAlongTheFacadeTheTreesAndTheWholeZoneAsAlternatives(): void
    {
        $plan = $this->sealedYard();
        $proposal = $this->tools()->proposePlacements($plan->id, O::PERMEABLE, count: 1);

        $yard = array_column($proposal['candidates'], null, 'id');
        self::assertSame(['band', 'trees', 'zone'], array_map(static fn (array $c): string => $c['metrics']['shape'], array_values($yard)), 'the gentlest first');
        $band = $proposal['candidates'][0];
        self::assertSame(O::KIND_PAVERS, $band['metrics']['kind']);
        self::assertSame(['permeable-2', 'permeable-3'], $band['metrics']['alternatives']);
        self::assertSame(O::KIND_GRAVEL, $proposal['candidates'][1]['metrics']['kind'], 'under the trees: no deep excavation');
        // the band keeps the drip strip and reaches about 7 m from the façade, the middle of the yard stays
        foreach ($band[O::POINTS] as [$x, $z]) {
            if ($x > -35000 && $x < -5000 && $z > -10000) {
                self::assertLessThanOrEqual(-10000 + 8000, $z, 'within the band');
            }
        }
        self::assertSame(0.0, Polygon::overlapArea($band[O::POINTS], self::SCHOOL), 'off the building');
        self::assertGreaterThan(0, $band['metrics']['runoff_removed_m3']);
    }

    #[Test]
    public function aCarParkGetsGrassPaversAndThePlaygroundKeepsItsSurfacing(): void
    {
        $proposal = $this->tools()->proposePlacements($this->plan()->id, O::PERMEABLE, count: 5);

        $carPark = array_values(array_filter($proposal['candidates'], static fn (array $c): bool => O::AREA_PARKING === $c['metrics']['zone_use']));
        self::assertNotEmpty($carPark);
        self::assertSame(O::KIND_GRASS_PAVERS, $carPark[0]['metrics']['kind']);
        self::assertSame([], array_filter($proposal['candidates'], static fn (array $c): bool => O::AREA_PLAY === $c['metrics']['zone_use']));
        self::assertArrayHasKey(PermeableSiting::KEPT[O::AREA_PLAY], $proposal['excluded_share_of_plot']);
    }

    #[Test]
    public function aUseTheUserSetsDecidesAndSurvivesAReimport(): void
    {
        $plan = $this->sealedYard();
        $tools = $this->tools();
        $yard = array_column($tools->getZones($plan->id)['zones'], null, O::USE)[O::AREA_YARD];

        self::assertTrue($tools->describeZone($plan->id, $yard['id'], use: O::AREA_ACCESS)['set']);

        $zones = array_column($tools->getZones($plan->id)['zones'], null, O::USE);
        self::assertSame(Zone::FROM_USER, $zones[O::AREA_ACCESS]['source']);
        $proposal = $tools->proposePlacements($plan->id, O::PERMEABLE, count: 5);
        self::assertSame([], array_filter($proposal['candidates'], static fn (array $c): bool => O::AREA_ACCESS === $c['metrics']['zone_use']), 'an access lane stays passable');
        self::assertArrayHasKey(PermeableSiting::KEPT[O::AREA_ACCESS], $proposal['excluded_share_of_plot']);

        // imported again (the map knows nothing of it): the user's use is back
        $carryOver = static::getContainer()->get(SiteCarryOver::class);
        $kept = $carryOver->capture($plan->id);
        $this->editor()->replaceSite($plan->id, (string) json_encode(['objects' => $this->objects()]));
        $carryOver->restore($plan->id, $kept);
        self::assertArrayHasKey(O::AREA_ACCESS, array_column($tools->getZones($plan->id)['zones'], null, O::USE));
    }

    #[Test]
    public function anUnknownZoneOrUseIsRefused(): void
    {
        $plan = $this->plan();

        $zone = $this->tools()->getZones($plan->id)['zones'][0]['id'];
        self::assertFalse($this->tools()->describeZone($plan->id, 'zone-nowhere', use: O::AREA_PLAY)['set']);
        self::assertFalse($this->tools()->describeZone($plan->id, $zone, use: 'helipad')['set']);
        self::assertFalse($this->tools()->describeZone($plan->id, $zone, surface: O::SURFACE_UNKNOWN)['set'], 'what the user says is never "unknown"');
        self::assertFalse($this->tools()->describeZone($plan->id, $zone)['set'], 'nothing said');
    }

    #[Test]
    public function theChosenAlternativeIsPlacedWithItsMaterialAndName(): void
    {
        $plan = $this->sealedYard();
        $tools = $this->tools();
        $band = $tools->proposePlacements($plan->id, O::PERMEABLE, count: 1)['candidates'][0];

        self::assertTrue($tools->placeCandidate($plan->id, $band['id'])['placed']);

        $placed = $this->editor()->site($plan->id)->ofType(O::PERMEABLE);
        self::assertCount(1, $placed);
        self::assertSame(O::KIND_PAVERS, $placed[0][O::KIND]);
        self::assertStringStartsWith('Pavers: Yard south', $placed[0][O::LABEL]);
        // what is paved now is not offered again
        $again = $tools->proposePlacements($plan->id, O::PERMEABLE, count: 1)['candidates'];
        self::assertNotContains('band', array_map(static fn (array $c): string => $c['metrics']['shape'], array_filter($again, static fn (array $c): bool => $c['metrics']['zone'] === $band['metrics']['zone'] && Polygon::overlapArea($c[O::POINTS], $band[O::POINTS]) > 1_000_000)));
    }

    /** the plan, after the user said the yard is asphalt */
    #[Test]
    public function playEquipmentInTheYardKeepsItsFallZone(): void
    {
        $plan = $this->sealedYard();
        $editor = static::getContainer()->get(SiteEditor::class);
        $editor->add($plan->id, [O::equipment(O::EQUIPMENT_CLIMBING, 0, 5000, look: O::LOOK_USER)]);
        $fallZone = Site::outlinesOf($editor->site($plan->id)->ofType(O::EQUIPMENT)[0])[0];

        $proposal = $this->tools()->proposePlacements($plan->id, O::PERMEABLE, count: 3);

        $whole = array_values(array_filter($proposal['candidates'], static fn (array $c): bool => 'zone' === $c['metrics']['shape'] && O::AREA_YARD === $c['metrics']['zone_use']));
        self::assertNotEmpty($whole, 'the yard is still paved around it');
        foreach ($proposal['candidates'] as $candidate) {
            self::assertLessThan(1.0, Polygon::overlapArea($candidate[O::POINTS], $fallZone) / 1e6, 'no paving in the fall zone');
        }
    }

    #[Test]
    public function equipmentMarkedByTheUserSurvivesAReimport(): void
    {
        $plan = $this->plan();
        $editor = static::getContainer()->get(SiteEditor::class);
        $editor->add($plan->id, [O::equipment(O::EQUIPMENT_SWING, 0, 5000, look: O::LOOK_USER), O::equipment(O::EQUIPMENT_SLIDE, 5000, 5000)]);
        $carryOver = static::getContainer()->get(SiteCarryOver::class);
        $kept = $carryOver->capture($plan->id);

        $editor->replaceSite($plan->id, (new SiteType())->normalize(['objects' => $this->objects()]));
        $carryOver->restore($plan->id, $kept);

        $equipment = $editor->site($plan->id)->ofType(O::EQUIPMENT);
        self::assertSame([O::EQUIPMENT_SWING], array_column($equipment, O::KIND), 'what the user marked, not what the map had (it comes again with the import)');
    }

    private function sealedYard(): Plan
    {
        $plan = $this->plan();
        $yard = array_column($this->tools()->getZones($plan->id)['zones'], null, O::USE)[O::AREA_YARD];
        self::assertTrue($this->tools()->describeZone($plan->id, $yard['id'], surface: O::SURFACE_ASPHALT)['set']);

        return $plan;
    }

    private function plan(): Plan
    {
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Schoolyard', 53.55, 10.02);
        $plan->replaceSite((new SiteType())->normalize(['objects' => $this->objects()]));
        $plans->save($plan);

        return $plan;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function objects(): array
    {
        return [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::BUILDING, O::LABEL => 'School', O::POINTS => self::SCHOOL, O::HEIGHT => 12000],
            [O::TYPE => O::TREE, O::X => self::TREE[0], O::Z => self::TREE[1], O::HEIGHT => 12000, O::DIAMETER => 8000],
            [O::TYPE => O::AREA, O::POINTS => self::CAR_PARK, O::USE => O::AREA_PARKING, O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::AREA, O::POINTS => self::PLAYGROUND, O::USE => O::AREA_PLAY, O::SURFACE => O::SURFACE_SOIL],
        ];
    }

    private function tools(): PlacementTools
    {
        return static::getContainer()->get(PlacementTools::class);
    }

    private function editor(): SiteEditor
    {
        return static::getContainer()->get(SiteEditor::class);
    }
}

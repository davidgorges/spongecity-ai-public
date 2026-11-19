<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Plan;

use App\Mcp\SpongeCityTools;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteCarryOver;
use App\SpongeCity\Plan\SiteEditor;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Interventions are planned in the open: what the agent draws onto a building is not placed,
 * and the agent is told which building it hit.
 */
final class BuildingClearanceTest extends KernelTestCase
{
    /** a 20 × 20 m school in the middle of a 100 × 100 m plot */
    private const array SCHOOL = [[0, 0], [20000, 0], [20000, 20000], [0, 20000]];
    private const array PLOT = [[-40000, -40000], [60000, -40000], [60000, 60000], [-40000, 60000]];

    #[Test]
    public function aRainGardenDrawnIntoTheSchoolComesBackAsAConflict(): void
    {
        $plan = $this->plan();

        $result = $this->tools()->addObjects($plan->id, [
            [O::TYPE => O::RAINGARDEN, O::LABEL => 'Into the wall', O::POINTS => [[15000, 5000], [25000, 5000], [25000, 11000], [15000, 11000]]],
            [O::TYPE => O::RAINGARDEN, O::LABEL => 'In the open', O::POINTS => [[25000, 5000], [35000, 5000], [35000, 11000], [25000, 11000]]],
        ]);

        self::assertCount(1, $result['ids']);
        self::assertSame(1, $result['rejected']);
        self::assertCount(1, $result['conflicts']);
        self::assertSame('Into the wall', $result['conflicts'][0][O::LABEL]);
        self::assertSame('School', $result['conflicts'][0]['building_label']);
        self::assertSame(30.0, $result['conflicts'][0]['overlap_m2'], '5 × 6 m under the school');
        self::assertStringContainsString('outside the building', $result['conflicts'][0]['reason']);
        self::assertSame(['In the open'], array_column($this->editor()->site($plan->id)->ofType(O::RAINGARDEN), O::LABEL));
    }

    #[Test]
    public function aTreeIsNotPlantedInsideTheSchool(): void
    {
        $plan = $this->plan();

        $result = $this->tools()->addObjects($plan->id, [[O::TYPE => O::NEWTREE, O::X => 10000, O::Z => 10000]]);

        self::assertSame([], $result['ids']);
        self::assertStringContainsString('Stands inside', $result['conflicts'][0]['reason']);
    }

    #[Test]
    public function pavingAgainstTheWallIsPlaced(): void
    {
        $plan = $this->plan();

        $result = $this->tools()->addObjects($plan->id, [[O::TYPE => O::PERMEABLE, O::POINTS => [[20000, 0], [30000, 0], [30000, 20000], [20000, 20000]]]]);

        self::assertCount(1, $result['ids']);
        self::assertSame([], $result['conflicts']);
    }

    #[Test]
    public function aMoveOntoTheSchoolIsNotMade(): void
    {
        $plan = $this->plan();
        $open = [[25000, 5000], [35000, 5000], [35000, 11000], [25000, 11000]];
        $id = $this->tools()->addObjects($plan->id, [[O::TYPE => O::PLANTING, O::POINTS => $open]])['ids'][0];

        $result = $this->tools()->updateObject($plan->id, $id, [O::POINTS => [[5000, 5000], [15000, 5000], [15000, 11000], [5000, 11000]]]);

        self::assertFalse($result['updated']);
        self::assertSame(60.0, $result['conflict']['overlap_m2']);
        self::assertEquals($open, $this->editor()->site($plan->id)->ofType(O::PLANTING)[0][O::POINTS], 'left where it was');
    }

    #[Test]
    public function theAgentSeesTheOutlinesOfTheBuildingsOnThePlot(): void
    {
        $plan = $this->plan();

        $buildings = $this->tools()->getSite($plan->id)['existing_buildings'];

        self::assertSame(self::SCHOOL, $buildings[0]['outline']);
        self::assertArrayNotHasKey('outline', $buildings[1], 'far off the plot, its centre is enough');
    }

    #[Test]
    public function aReimportLeavesOutWhatANewBuildingNowStandsOn(): void
    {
        $plan = $this->plan();
        $this->tools()->addObjects($plan->id, [[O::TYPE => O::RAINGARDEN, O::LABEL => 'East', O::POINTS => [[25000, 5000], [35000, 5000], [35000, 11000], [25000, 11000]]]]);
        $carryOver = static::getContainer()->get(SiteCarryOver::class);
        $kept = $carryOver->capture($plan->id);

        // the new data has the school's extension where the rain garden was
        $this->editor()->replaceSite($plan->id, (string) json_encode(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT],
            [O::TYPE => O::BUILDING, O::LABEL => 'School', O::POINTS => [[0, 0], [40000, 0], [40000, 20000], [0, 20000]], O::HEIGHT => 12000],
        ]]));
        $restored = $carryOver->restore($plan->id, $kept);

        self::assertCount(1, $restored['conflicts']);
        self::assertSame('East', $restored['conflicts'][0][O::LABEL]);
        self::assertSame([], $this->editor()->site($plan->id)->ofType(O::RAINGARDEN));
    }

    private function plan(): Plan
    {
        $plans = static::getContainer()->get(PlanStore::class);
        $plan = $plans->create('Clearance', 53.6, 10.1);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT],
            [O::TYPE => O::BUILDING, O::LABEL => 'School', O::POINTS => self::SCHOOL, O::HEIGHT => 12000],
            [O::TYPE => O::BUILDING, O::LABEL => 'Far away', O::POINTS => [[90000, 90000], [95000, 90000], [95000, 95000], [90000, 95000]], O::HEIGHT => 6000],
        ]]));
        $plans->save($plan);

        return $plan;
    }

    private function tools(): SpongeCityTools
    {
        return static::getContainer()->get(SpongeCityTools::class);
    }

    private function editor(): SiteEditor
    {
        return static::getContainer()->get(SiteEditor::class);
    }
}

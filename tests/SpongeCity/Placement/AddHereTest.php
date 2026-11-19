<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Placement;

use App\SpongeCity\Geo\Hamburg\HamburgTerrain;
use App\SpongeCity\Placement\AddHere;
use App\SpongeCity\Placement\OpenGround;
use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\Terrain\Terrain;
use App\SpongeCity\Terrain\TerrainGrid;
use App\SpongeCity\Terrain\TerrainStore;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Add here" from the 3D view: by the rules, not where the click was. An 80 × 60 m asphalt yard
 * falling towards a bowl in its south-east, a school in the north-west, a playground beside it.
 */
final class AddHereTest extends KernelTestCase
{
    private const array PLOT = [[-40000, -30000], [40000, -30000], [40000, 30000], [-40000, 30000]];
    private const array SCHOOL = [[-35000, -25000], [-5000, -25000], [-5000, -10000], [-35000, -10000]];
    private const array PLAYGROUND = [[-35000, 5000], [-15000, 5000], [-15000, 25000], [-35000, 25000]];
    /** where the water gathers, plan metres */
    private const array BOWL = [20.0, 10.0];

    #[Test]
    public function aRainGardenGoesWhereTheWaterNearThePointGathers(): void
    {
        $plan = $this->plan();

        // clicked 8 m beside the bowl
        $result = $this->addHere()->add($plan, O::RAINGARDEN, 12000, 10000);

        self::assertSame(AddHere::ADDED.'.'.O::RAINGARDEN, $result['outcome']);
        $garden = $this->object($plan, $result['id'] ?? '');
        self::assertTrue(Polygon::contains($garden[O::POINTS], 20000, 10000), 'in the bowl, not under the pointer');
        self::assertGreaterThan(0, $result['params']['%distance%']);
    }

    #[Test]
    public function pavingNeedsAZoneWhoseGroundIsKnown(): void
    {
        $plan = $this->plan();

        $unknown = $this->addHere()->add($plan, O::PERMEABLE, 0, 20000);
        self::assertSame(AddHere::ZONE_UNKNOWN, $unknown['outcome'], 'it may be a lawn');
        self::assertArrayHasKey('id', $unknown, 'the zone, to say what it is');

        $playground = $this->addHere()->add($plan, O::PERMEABLE, -25000, 15000);
        self::assertSame(AddHere::ZONE_KEPT, $playground['outcome']);
        self::assertSame(O::AREA_PLAY, $playground['params']['%use%']);
    }

    #[Test]
    public function aShadeTreeStandsOnlyOnFreeGround(): void
    {
        $plan = $this->plan();
        $tree = $this->addHere()->add($plan, O::NEWTREE, 10000, 0);
        self::assertSame(AddHere::ADDED.'.'.O::NEWTREE, $tree['outcome']);
        self::assertSame([10000, 0], [$this->object($plan, $tree['id'] ?? '')[O::X], $this->object($plan, $tree['id'] ?? '')[O::Z]], 'where it was pointed at');

        self::assertSame(AddHere::BLOCKED, $this->addHere()->add($plan, O::NEWTREE, -20000, -20000)['outcome'], 'on the school');
        self::assertSame(AddHere::BLOCKED, $this->addHere()->add($plan, O::NEWTREE, -20000, -8000)['outcome'], 'against its wall');
        // as the workspace does on every action: the plan as it is now
        $nextTo = $this->addHere()->add(static::getContainer()->get(PlanStore::class)->get($plan->id), O::NEWTREE, 11000, 0);
        self::assertSame(['%reason%' => OpenGround::ROOT_ZONE], $nextTo['params'], 'in the first one\'s root zone');
        self::assertSame(AddHere::OFF_PLOT, $this->addHere()->add($plan, O::NEWTREE, 60000, 0)['outcome']);
    }

    #[Test]
    public function playEquipmentIsMarkedWhereItWasPointedAt(): void
    {
        $plan = $this->plan();

        $result = $this->addHere()->add($plan, O::EQUIPMENT, -25000, 15000);

        $item = $this->object($plan, $result['id'] ?? '');
        self::assertSame(O::EQUIPMENT, $item[O::TYPE]);
        self::assertSame(O::LOOK_USER, $item[O::LOOK]);
    }

    private function plan(): Plan
    {
        $container = static::getContainer();
        $plans = $container->get(PlanStore::class);
        $plan = $plans->create('Yard', 53.55, 10.02);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::PLOT, O::POINTS => self::PLOT, O::SURFACE => O::SURFACE_ASPHALT],
            [O::TYPE => O::BUILDING, O::LABEL => 'School', O::POINTS => self::SCHOOL, O::HEIGHT => 12000],
            [O::TYPE => O::AREA, O::POINTS => self::PLAYGROUND, O::USE => O::AREA_PLAY, O::SURFACE => O::SURFACE_SOIL],
        ]]));
        $plans->save($plan);
        // 2 m nodes from -60 to +60 m, rising 3 cm per metre away from the bowl
        $heights = [];
        for ($r = 0; $r < 61; ++$r) {
            for ($c = 0; $c < 61; ++$c) {
                $heights[] = (int) round(3 * hypot(-60 + 2 * $c - self::BOWL[0], -60 + 2 * $r - self::BOWL[1]));
            }
        }
        $grid = new TerrainGrid(-60.0, -60.0, 2.0, 61, 61, $heights);
        $container->get(TerrainStore::class)->save($plan->id, new Terrain(5.0, $grid, $grid, HamburgTerrain::source()));

        return $plan;
    }

    /**
     * @return array<string, mixed>
     */
    private function object(Plan $plan, string $id): array
    {
        foreach (static::getContainer()->get(SiteEditor::class)->site($plan->id)->objects as $object) {
            if ($object[O::ID] === $id) {
                return $object;
            }
        }
        self::fail(sprintf('no object "%s"', $id));
    }

    private function addHere(): AddHere
    {
        return static::getContainer()->get(AddHere::class);
    }
}

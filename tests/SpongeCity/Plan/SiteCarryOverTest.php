<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Plan;

use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteCarryOver;
use App\SpongeCity\Plan\SiteEditor;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a person added to a site survives its re-import.
 */
final class SiteCarryOverTest extends KernelTestCase
{
    /** a 40 × 30 m U open to the south: the centre of its corners lies in the courtyard */
    private const array U = [[0, 0], [40000, 0], [40000, 30000], [30000, 30000], [30000, 10000], [10000, 10000], [10000, 30000], [0, 30000]];

    #[Test]
    public function aCorrectedLookFindsItsUShapedBuildingAgain(): void
    {
        $container = static::getContainer();
        $plans = $container->get(PlanStore::class);
        $editor = $container->get(SiteEditor::class);
        $carryOver = $container->get(SiteCarryOver::class);
        $plan = $plans->create('U', 53.55, 10.02);
        $plan->replaceSite((new SiteType())->normalize(['objects' => [
            [O::TYPE => O::BUILDING, O::POINTS => self::U, O::HEIGHT => 15000, O::FACADE => 'render'],
            [O::TYPE => O::NEWTREE, O::X => 20000, O::Z => 20000],
        ]]));
        $plans->save($plan);
        $id = (string) $editor->site($plan->id)->ofType(O::BUILDING)[0][O::ID];
        $editor->update($plan->id, $id, [O::FACADE => 'brick', O::COLOR => '#6e3428']);

        $kept = $carryOver->capture($plan->id);
        // the import draws the same building again, a few corners off, with the data's look
        $editor->replaceSite($plan->id, (string) json_encode(['objects' => [
            [O::TYPE => O::BUILDING, O::POINTS => array_map(static fn (array $p): array => [$p[0] + 300, $p[1] - 200], self::U), O::HEIGHT => 15000, O::FACADE => 'render'],
        ]]));

        self::assertSame(['looks' => 1, 'conflicts' => []], $carryOver->restore($plan->id, $kept));
        $site = $editor->site($plan->id);
        self::assertSame('brick', $site->ofType(O::BUILDING)[0][O::FACADE]);
        self::assertSame('#6e3428', $site->ofType(O::BUILDING)[0][O::COLOR]);
        self::assertCount(1, $site->ofType(O::NEWTREE), 'the planned tree is back');
    }
}

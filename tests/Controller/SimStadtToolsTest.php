<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Mcp\SimStadtTools;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\SimStadt\RunSimulation;
use App\SpongeCity\SimStadt\SimStadtWorkflow;
use App\SpongeCity\SimStadt\Simulation;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The agent's SimStadt tools as the MCP server calls them: a run is queued for the worker and polled.
 */
final class SimStadtToolsTest extends KernelTestCase
{
    #[Test]
    public function queuesARunAndReportsItsStatus(): void
    {
        $container = static::getContainer();
        $plan = $container->get(PlanStore::class)->create('Brecht-Schule', 53.5513, 10.0188);
        $tools = $container->get(SimStadtTools::class);

        $started = $tools->simulateBuildings($plan->id, SimStadtWorkflow::PvPotential->value, 1990);

        self::assertSame(Simulation::STATUS_QUEUED, $started['status']);
        $transport = $container->get('messenger.transport.simstadt');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $message = $transport->getSent()[0]->getMessage();
        self::assertInstanceOf(RunSimulation::class, $message);
        self::assertSame(1990, $message->yearOfConstruction);

        self::assertSame(Simulation::STATUS_QUEUED, $tools->getBuildingSimulation($plan->id, 'pv_potential')['status']);
        self::assertStringContainsString('already running', $tools->simulateBuildings($plan->id, 'pv_potential')['note'], 'one run at a time');
        self::assertSame('none', $tools->getBuildingSimulation($plan->id, 'heat_demand')['status']);
    }

    #[Test]
    public function refusesPlansOutsideHamburgAndUnknownWorkflows(): void
    {
        $container = static::getContainer();
        $tools = $container->get(SimStadtTools::class);
        $berlin = $container->get(PlanStore::class)->create('Berlin', 52.52, 13.40);

        self::assertStringContainsString('outside Hamburg', $tools->simulateBuildings($berlin->id, 'heat_demand')['error']);
        self::assertArrayHasKey('pv_potential', $tools->simulateBuildings($berlin->id, 'cooling')['workflows']);
    }
}

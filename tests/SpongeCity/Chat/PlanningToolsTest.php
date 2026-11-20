<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Chat;

use App\Mcp\CoolingTools;
use App\Mcp\SpongeCityTools;
use App\SpongeCity\Chat\PlanAssistant;
use App\SpongeCity\Plan\PlanStore;
use PHPUnit\Framework\Attributes\Test;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The agent's tools as the agent gets them: this application's MCP server, started as a child
 * process over stdio (bin/console mcp:server), no URL and no network in between.
 */
final class PlanningToolsTest extends KernelTestCase
{
    private const string PREFIX = 'local_';

    #[Test]
    public function theAgentListsTheServersToolsAndCallsThemOnTheSamePlans(): void
    {
        $container = static::getContainer();
        /** @var ToolboxInterface $tools */
        $tools = $container->get(PlanAssistant::PLANNING_TOOLBOX);
        $plan = $container->get(PlanStore::class)->create('Schulhof', 53.55, 10.0);
        $container->get(PlanStore::class)->save($plan);

        $names = array_map(static fn (Tool $tool): string => $tool->getName(), $tools->getTools());
        self::assertContains(self::PREFIX.CoolingTools::TOOL_COOLING, $names);
        self::assertContains(self::PREFIX.SpongeCityTools::TOOL_GET_SITE, $names);

        $site = $tools->execute(new ToolCall('call-1', self::PREFIX.SpongeCityTools::TOOL_GET_SITE, ['planId' => $plan->id]))->getResult();
        self::assertSame($plan->id, $site['plan'] ?? null, 'the child process sees the plan this process stored');
    }
}

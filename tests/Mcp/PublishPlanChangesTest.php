<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\SpongeCityTools;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\PlanUpdates;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * An agent connected over MCP changes a plan; every page showing it hears so on the plan's topic
 * and re-renders. Reading, or a call that failed, changes nothing and says nothing.
 */
final class PublishPlanChangesTest extends WebTestCase
{
    use ForgetsRateLimits;

    /** @var list<Update> */
    private array $published = [];

    #[Test]
    public function aToolThatChangesThePlanTellsThePagesShowingIt(): void
    {
        [$mcp, $planId] = $this->agentOnAPlan();

        $answer = $mcp->call(SpongeCityTools::TOOL_SET_STORM, ['planId' => $planId, 'millimetresPerHour' => 60]);

        self::assertFalse($answer['result']['isError'] ?? false, json_encode($answer, \JSON_THROW_ON_ERROR));
        self::assertCount(1, $this->published);
        self::assertSame([PlanUpdates::topic($planId)], $this->published[0]->getTopics());
        self::assertSame([PlanUpdates::KEY_CHANGED => SpongeCityTools::TOOL_SET_STORM], json_decode($this->published[0]->getData(), true));
    }

    #[Test]
    public function readingOrAFailedCallTellsNobody(): void
    {
        [$mcp, $planId] = $this->agentOnAPlan();

        $mcp->call(SpongeCityTools::TOOL_GET_SITE, ['planId' => $planId]);
        $failed = $mcp->call(SpongeCityTools::TOOL_SET_STORM, ['planId' => 'no-such-plan', 'millimetresPerHour' => 60]);

        self::assertTrue($failed['result']['isError'] ?? isset($failed['error']), json_encode($failed, \JSON_THROW_ON_ERROR));
        self::assertSame([], $this->published);
    }

    /**
     * @return array{McpClient, string}
     */
    private function agentOnAPlan(): array
    {
        $browser = static::createClient();
        $browser->disableReboot();
        self::forgetRateLimits($browser);
        $this->recordPublishedUpdates($browser);
        $plan = static::getContainer()->get(PlanStore::class)->create('Brecht-Schule, Hamburg', 53.5564, 10.0145);
        $mcp = new McpClient($browser);
        $mcp->initialize();

        return [$mcp, $plan->id];
    }

    private function recordPublishedUpdates(KernelBrowser $browser): void
    {
        $browser->getContainer()->set('mercure.hub.default.traceable', new MockHub('https://hub.test/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id';
        }));
    }
}

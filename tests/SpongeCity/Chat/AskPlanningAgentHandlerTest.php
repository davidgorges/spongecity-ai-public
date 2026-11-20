<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Chat;

use App\SpongeCity\Chat\AskPlanningAgent;
use App\SpongeCity\Chat\AskPlanningAgentHandler;
use App\SpongeCity\Chat\ChatStream;
use App\SpongeCity\Chat\ConversationStore;
use App\SpongeCity\Chat\PlanAssistant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\MockAgent;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

/**
 * The worker publishes the agent's reply on the plan's topic, or that it failed: the page waits
 * for one or the other.
 */
final class AskPlanningAgentHandlerTest extends TestCase
{
    private const string PLAN = 'plan-1';

    /** @var list<Update> */
    private array $published = [];

    #[Test]
    public function theReplyIsPublishedOnThePlansTopic(): void
    {
        $agent = new MockAgent([sprintf(PlanAssistant::MESSAGE_FORMAT, self::PLAN, 'de', 'Add shade') => 'Two trees on the south side.']);

        $this->handler($agent, self::tools())(new AskPlanningAgent(self::PLAN, 'Add shade', 'de'));

        self::assertCount(1, $this->published);
        self::assertSame([ChatStream::topic(self::PLAN)], $this->published[0]->getTopics());
        self::assertSame([ChatStream::KEY_REPLY => 'Two trees on the south side.'], json_decode($this->published[0]->getData(), true));
    }

    #[Test]
    public function aFailedTurnIsPublishedAsAnError(): void
    {
        // without its tools the agent is not asked (PlanningToolsUnavailable)
        $this->handler(new MockAgent([]), self::tools([]))(new AskPlanningAgent(self::PLAN, 'Add shade', 'en'));

        self::assertCount(1, $this->published);
        self::assertSame([ChatStream::KEY_ERROR => ChatStream::ERROR_FAILED], json_decode($this->published[0]->getData(), true));
    }

    private function handler(MockAgent $agent, ToolboxInterface $tools): AskPlanningAgentHandler
    {
        $hub = new MockHub('https://hub.test/.well-known/mercure', new StaticTokenProvider('token'), function (Update $update): string {
            $this->published[] = $update;

            return 'id';
        });

        return new AskPlanningAgentHandler(new PlanAssistant($agent, new ConversationStore(new ArrayAdapter()), $tools), new ChatStream($hub), new NullLogger());
    }

    /**
     * @param list<Tool>|null $tools null: one tool, as the MCP server lists them
     */
    private static function tools(?array $tools = null): ToolboxInterface
    {
        $tools ??= [new Tool(new ExecutionReference(self::class, 'get_site'), 'local_get_site', 'The site.')];

        return new class($tools) implements ToolboxInterface {
            /** @param list<Tool> $tools */
            public function __construct(private readonly array $tools)
            {
            }

            public function getTools(): array
            {
                return $this->tools;
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                throw new \LogicException('not called');
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Chat;

use App\SpongeCity\Chat\ConversationStore;
use App\SpongeCity\Chat\PlanAssistant;
use App\SpongeCity\Chat\PlanningToolsUnavailable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\MockAgent;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The conversation is kept with the plan: another browser sees it, and the agent hears it again on
 * the next turn. The plan id and the interface's language travel with every message.
 */
final class PlanAssistantTest extends TestCase
{
    private const string PLAN = 'plan-1';

    #[Test]
    public function theTurnIsKeptWithThePlan(): void
    {
        $agent = new MockAgent([
            sprintf(PlanAssistant::MESSAGE_FORMAT, self::PLAN, 'de', 'Add shade') => 'Two trees on the south side.',
        ]);
        $conversations = new ConversationStore(new ArrayAdapter());
        $assistant = new PlanAssistant($agent, $conversations, self::tools());

        $reply = $assistant->ask(self::PLAN, 'Add shade', 'de');

        self::assertSame('Two trees on the south side.', $reply);
        $expected = [['role' => PlanAssistant::ROLE_USER, 'content' => 'Add shade'], ['role' => PlanAssistant::ROLE_ASSISTANT, 'content' => 'Two trees on the south side.']];
        $elsewhere = new PlanAssistant($agent, $conversations, self::tools());
        self::assertSame($expected, $elsewhere->history(self::PLAN), 'another browser sees the conversation');
        self::assertSame([], $elsewhere->history('plan-2'), 'per plan');
    }

    #[Test]
    public function withoutItsToolsTheAgentIsNotAskedAndNothingIsRecorded(): void
    {
        $agent = new MockAgent([
            sprintf(PlanAssistant::MESSAGE_FORMAT, self::PLAN, 'en', 'Add shade') => '<invoke name="local_get_site">',
        ]);
        $conversations = new ConversationStore(new ArrayAdapter());
        $assistant = new PlanAssistant($agent, $conversations, self::tools([]));

        try {
            $assistant->ask(self::PLAN, 'Add shade', 'en');
            self::fail('a turn without tools must not run');
        } catch (PlanningToolsUnavailable) {
        }
        self::assertSame([], $conversations->of(self::PLAN)->turns);
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

<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Chat;

use App\SpongeCity\Chat\ConversationStore;
use App\SpongeCity\Chat\PlanAssistant;
use App\SpongeCity\Chat\PlanningToolsUnavailable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\MockAgent;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * A turn of the agent takes about a minute; the page's own requests meanwhile must not wait for
 * the session, which PHP locks while it is open. The conversation is kept with the plan: another
 * browser (another session) sees it, and the agent hears it again on the next turn.
 */
final class PlanAssistantTest extends TestCase
{
    private const string PLAN = 'plan-1';

    #[Test]
    public function theSessionIsClosedWhileTheAgentWorksAndTheTurnIsKeptWithThePlan(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $openDuringTheTurn = null;
        $agent = new MockAgent([
            sprintf(PlanAssistant::MESSAGE_FORMAT, self::PLAN, 'en', 'Add shade') => static function () use ($session, &$openDuringTheTurn): string {
                $openDuringTheTurn = $session->isStarted();

                return 'Two trees on the south side.';
            },
        ]);
        $conversations = new ConversationStore(new ArrayAdapter());
        $assistant = new PlanAssistant($agent, $this->requestsWith($session), $conversations, 'en', self::tools());

        $reply = $assistant->ask(self::PLAN, 'Add shade');

        self::assertSame('Two trees on the south side.', $reply);
        self::assertFalse($openDuringTheTurn, 'the session is not locked while the agent works');
        $expected = [['role' => PlanAssistant::ROLE_USER, 'content' => 'Add shade'], ['role' => PlanAssistant::ROLE_ASSISTANT, 'content' => 'Two trees on the south side.']];
        $elsewhere = new PlanAssistant($agent, $this->requestsWith(new Session(new MockArraySessionStorage())), $conversations, 'en', self::tools());
        self::assertSame($expected, $elsewhere->history(self::PLAN), 'another browser sees the conversation');
        self::assertSame([], $elsewhere->history('plan-2'), 'per plan');
    }

    #[Test]
    public function aSessionThatNeverStartedDoesNotStopTheTurn(): void
    {
        $agent = new MockAgent([
            sprintf(PlanAssistant::MESSAGE_FORMAT, self::PLAN, 'en', 'Add shade') => 'Two trees on the south side.',
        ]);
        $assistant = new PlanAssistant($agent, $this->requestsWith(new Session(new MockArraySessionStorage())), new ConversationStore(new ArrayAdapter()), 'en', self::tools());

        self::assertSame('Two trees on the south side.', $assistant->ask(self::PLAN, 'Add shade'));
    }

    #[Test]
    public function withoutItsToolsTheAgentIsNotAskedAndNothingIsRecorded(): void
    {
        $agent = new MockAgent([
            sprintf(PlanAssistant::MESSAGE_FORMAT, self::PLAN, 'en', 'Add shade') => '<invoke name="local_get_site">',
        ]);
        $conversations = new ConversationStore(new ArrayAdapter());
        $assistant = new PlanAssistant($agent, $this->requestsWith(new Session(new MockArraySessionStorage())), $conversations, 'en', self::tools([]));

        try {
            $assistant->ask(self::PLAN, 'Add shade');
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

    private function requestsWith(Session $session): RequestStack
    {
        $request = new Request();
        $request->setSession($session);
        $requests = new RequestStack();
        $requests->push($request);

        return $requests;
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Chat;

use App\SpongeCity\Chat\ConversationStore;
use App\SpongeCity\Chat\PlanAssistant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\MockAgent;
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
        $assistant = new PlanAssistant($agent, $this->requestsWith($session), $conversations, 'en');

        $reply = $assistant->ask(self::PLAN, 'Add shade');

        self::assertSame('Two trees on the south side.', $reply);
        self::assertFalse($openDuringTheTurn, 'the session is not locked while the agent works');
        $expected = [['role' => PlanAssistant::ROLE_USER, 'content' => 'Add shade'], ['role' => PlanAssistant::ROLE_ASSISTANT, 'content' => 'Two trees on the south side.']];
        $elsewhere = new PlanAssistant($agent, $this->requestsWith(new Session(new MockArraySessionStorage())), $conversations, 'en');
        self::assertSame($expected, $elsewhere->history(self::PLAN), 'another browser sees the conversation');
        self::assertSame([], $elsewhere->history('plan-2'), 'per plan');
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

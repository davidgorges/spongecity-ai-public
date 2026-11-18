<?php

declare(strict_types=1);

namespace App\Tests\SpongeCity\Chat;

use App\SpongeCity\Chat\PlanAssistant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\MockAgent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * A turn of the agent takes about a minute; the page's own requests meanwhile must not wait for
 * the session, which PHP locks while it is open.
 */
final class PlanAssistantTest extends TestCase
{
    private const string PLAN = 'plan-1';

    #[Test]
    public function theSessionIsClosedWhileTheAgentWorksAndTheTurnIsKeptAfterwards(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request = new Request();
        $request->setSession($session);
        $requests = new RequestStack();
        $requests->push($request);
        $openDuringTheTurn = null;
        $agent = new MockAgent([
            sprintf("plan_id: %s\n\n%s", self::PLAN, 'Add shade') => static function () use ($session, &$openDuringTheTurn): string {
                $openDuringTheTurn = $session->isStarted();

                return 'Two trees on the south side.';
            },
        ]);
        $assistant = new PlanAssistant($agent, $requests);

        $reply = $assistant->ask(self::PLAN, 'Add shade');

        self::assertSame('Two trees on the south side.', $reply);
        self::assertFalse($openDuringTheTurn, 'the session is not locked while the agent works');
        self::assertSame([['role' => PlanAssistant::ROLE_USER, 'content' => 'Add shade'], ['role' => PlanAssistant::ROLE_ASSISTANT, 'content' => 'Two trees on the south side.']], $assistant->history(self::PLAN));
    }
}

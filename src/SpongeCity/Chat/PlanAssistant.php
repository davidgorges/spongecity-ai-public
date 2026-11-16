<?php

declare(strict_types=1);

namespace App\SpongeCity\Chat;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Talks to the planning agent about one plan. The conversation is kept in the
 * session per plan, so user and agent refine the design turn by turn.
 */
final readonly class PlanAssistant
{
    public const string ROLE_USER = 'user';
    public const string ROLE_ASSISTANT = 'assistant';
    private const string SESSION_PREFIX = 'spongecity_chat_';
    private const int MAX_TURNS = 20;

    public function __construct(
        #[Autowire(service: 'ai.agent.spongecity')]
        private AgentInterface $agent,
        private RequestStack $requests,
    ) {
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function history(string $planId): array
    {
        $history = $this->requests->getSession()->get(self::SESSION_PREFIX.$planId, []);

        return is_array($history) ? array_values($history) : [];
    }

    public function ask(string $planId, string $message): string
    {
        $history = $this->history($planId);
        $bag = new MessageBag();
        foreach ($history as $turn) {
            $bag = $bag->with(self::ROLE_USER === $turn['role'] ? Message::ofUser($turn['content']) : Message::ofAssistant($turn['content']));
        }
        // the plan id travels with every message: the tools are stateless
        $bag = $bag->with(Message::ofUser(sprintf("plan_id: %s\n\n%s", $planId, $message)));

        $reply = $this->agent->call($bag)->asText();

        $history[] = ['role' => self::ROLE_USER, 'content' => $message];
        $history[] = ['role' => self::ROLE_ASSISTANT, 'content' => $reply];
        $this->requests->getSession()->set(self::SESSION_PREFIX.$planId, array_slice($history, -self::MAX_TURNS * 2));

        return $reply;
    }

    public function reset(string $planId): void
    {
        $this->requests->getSession()->remove(self::SESSION_PREFIX.$planId);
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Chat;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Talks to the planning agent about one plan. The conversation is kept with the plan
 * (ConversationStore), so user and agent refine the design turn by turn, across reloads.
 */
final readonly class PlanAssistant
{
    public const string ROLE_USER = Conversation::ROLE_USER;
    public const string ROLE_ASSISTANT = Conversation::ROLE_ASSISTANT;
    /** what travels with every message: the plan, and the language the interface shows the agent's reasons in */
    public const string MESSAGE_FORMAT = "plan_id: %s\ninterface_language: %s\n\n%s";
    /** the agent's tools: this application's MCP server, started over stdio (ai.yaml: mcp_server 'spongecity.local') */
    public const string PLANNING_TOOLBOX = 'ai.toolbox.spongecity.mcp.spongecity.local';

    public function __construct(
        #[Autowire(service: 'ai.agent.spongecity')]
        private AgentInterface $agent,
        private RequestStack $requests,
        private ConversationStore $conversations,
        #[Autowire(param: 'kernel.default_locale')]
        private string $defaultLocale,
        #[Autowire(service: self::PLANNING_TOOLBOX)]
        private ToolboxInterface $planningTools,
    ) {
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    public function history(string $planId): array
    {
        return $this->conversations->of($planId)->turns;
    }

    public function ask(string $planId, string $message): string
    {
        // a server that cannot be listed contributes no tools, silently: the model then writes its
        // tool calls as text into the reply. The listing is kept, the agent does not list again.
        if ([] === $this->planningTools->getTools()) {
            throw PlanningToolsUnavailable::fromServer();
        }

        $bag = new MessageBag();
        foreach ($this->history($planId) as $turn) {
            $bag = $bag->with(self::ROLE_USER === $turn[Conversation::KEY_ROLE] ? Message::ofUser($turn[Conversation::KEY_CONTENT]) : Message::ofAssistant($turn[Conversation::KEY_CONTENT]));
        }
        // the plan id travels with every message (the tools are stateless), and the interface's
        // language: the reasons the agent gives for what it places are shown in it
        $language = $this->requests->getCurrentRequest()?->getLocale() ?? $this->defaultLocale;
        $bag = $bag->with(Message::ofUser(sprintf(self::MESSAGE_FORMAT, $planId, $language, $message)));

        // the session is locked while it is open: a turn takes a minute, and the page's own requests
        // meanwhile (the re-renders that show the design appear) would wait for it until they time out
        // (only a started one: saving a session that never started fails, there is no $_SESSION yet)
        $session = $this->requests->getCurrentRequest()?->hasSession() ? $this->requests->getSession() : null;
        if ($session?->isStarted()) {
            $session->save();
        }
        $reply = $this->agent->call($bag)->asText();
        $this->conversations->record($planId, $message, $reply);

        return $reply;
    }

    public function reset(string $planId): void
    {
        $this->conversations->forget($planId);
    }
}

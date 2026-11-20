<?php

declare(strict_types=1);

namespace App\SpongeCity\Chat;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs one turn of the planning agent in a worker and publishes its reply, or that it failed:
 * the page is waiting for one or the other. A failed turn is not retried (it may already have
 * changed the plan); the person can ask again.
 */
#[AsMessageHandler]
final readonly class AskPlanningAgentHandler
{
    public function __construct(
        private PlanAssistant $assistant,
        private ChatStream $stream,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(AskPlanningAgent $message): void
    {
        try {
            $reply = $this->assistant->ask($message->planId, $message->message, $message->language);
        } catch (\Throwable $exception) {
            $this->logger->error('The planning agent failed', ['plan' => $message->planId, 'exception' => $exception]);
            $this->stream->failed($message->planId);

            return;
        }
        $this->stream->replied($message->planId, $reply);
    }
}

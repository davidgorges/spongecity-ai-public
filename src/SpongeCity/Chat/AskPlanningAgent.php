<?php

declare(strict_types=1);

namespace App\SpongeCity\Chat;

/**
 * One message of the person to the planning agent, answered by a worker: a turn is several model
 * calls with tools in between, often longer than a web request may take. The reply reaches the
 * page through Mercure (ChatStream).
 */
final readonly class AskPlanningAgent
{
    public function __construct(
        public string $planId,
        public string $message,
        /** the interface's language: the agent writes the reasons for what it places in it */
        public string $language,
    ) {
    }
}

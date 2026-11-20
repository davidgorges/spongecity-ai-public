<?php

declare(strict_types=1);

namespace App\SpongeCity\Chat;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tells the plan's page what the planning agent answered, through the Mercure hub. Every page
 * showing the plan listens to its topic (plan_chat_controller.js); the updates are public, like
 * the plan itself, which anyone with its id can open.
 */
final readonly class ChatStream
{
    public const string TOPIC_FORMAT = 'spongecity/plan/%s/chat';
    public const string KEY_REPLY = 'reply';
    public const string KEY_ERROR = 'error';
    /** the translation key of the message shown when the agent could not answer */
    public const string ERROR_FAILED = 'plan.chat.failed';

    public function __construct(
        private HubInterface $hub,
    ) {
    }

    public static function topic(string $planId): string
    {
        return sprintf(self::TOPIC_FORMAT, $planId);
    }

    public function replied(string $planId, string $reply): void
    {
        $this->publish($planId, [self::KEY_REPLY => $reply]);
    }

    public function failed(string $planId): void
    {
        $this->publish($planId, [self::KEY_ERROR => self::ERROR_FAILED]);
    }

    /**
     * @param array<string, string> $data
     */
    private function publish(string $planId, array $data): void
    {
        $this->hub->publish(new Update(self::topic($planId), json_encode($data, \JSON_THROW_ON_ERROR)));
    }
}

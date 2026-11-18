<?php

declare(strict_types=1);

namespace App\SpongeCity\Chat;

use App\SpongeCity\CacheStore;

/**
 * The conversation about each plan, kept with the plan (not in a browser's session): it is
 * there again after a reload, in another browser, and for whoever opens the plan.
 *
 * @extends CacheStore<Conversation>
 */
final readonly class ConversationStore extends CacheStore
{
    private const string KEY_PREFIX = 'spongecity_conversation_';

    protected function type(): string
    {
        return Conversation::class;
    }

    public function of(string $planId): Conversation
    {
        return $this->fetch(self::KEY_PREFIX.$planId) ?? new Conversation();
    }

    public function record(string $planId, string $question, string $reply): void
    {
        $this->store(self::KEY_PREFIX.$planId, $this->of($planId)->with($question, $reply));
    }

    public function forget(string $planId): void
    {
        $this->remove(self::KEY_PREFIX.$planId);
    }
}

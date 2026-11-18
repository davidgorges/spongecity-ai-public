<?php

declare(strict_types=1);

namespace App\SpongeCity\Chat;

/**
 * What the person and the planning agent said about one plan, oldest first, the last MAX_TURNS
 * exchanges.
 */
final readonly class Conversation
{
    public const string ROLE_USER = 'user';
    public const string ROLE_ASSISTANT = 'assistant';
    public const string KEY_ROLE = 'role';
    public const string KEY_CONTENT = 'content';
    private const int MAX_TURNS = 20;

    /**
     * @param list<array{role: string, content: string}> $turns
     */
    public function __construct(public array $turns = [])
    {
    }

    public function with(string $question, string $reply): self
    {
        $turns = [
            ...$this->turns,
            [self::KEY_ROLE => self::ROLE_USER, self::KEY_CONTENT => $question],
            [self::KEY_ROLE => self::ROLE_ASSISTANT, self::KEY_CONTENT => $reply],
        ];

        return new self(array_values(array_slice($turns, -self::MAX_TURNS * 2)));
    }
}

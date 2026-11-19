<?php

declare(strict_types=1);

namespace App\Controller\Plan;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the chat posts: one message of the user to the planning agent.
 */
final readonly class ChatMessage
{
    public const int MAX_LENGTH = 2000;

    public function __construct(
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: self::MAX_LENGTH)]
        public string $message = '',
    ) {
    }
}

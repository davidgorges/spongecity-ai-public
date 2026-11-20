<?php

declare(strict_types=1);

namespace App\SpongeCity\Scenario;

/**
 * A named set of interventions of a plan, kept to compare and to go back to: "rain gardens only",
 * "with the green roof". The city around them is the plan's, so a scenario stays comparable when
 * the plan's site is corrected later.
 */
final readonly class Scenario
{
    public const int MAX_NAME = 60;

    /**
     * @param list<array<string, mixed>> $interventions the plan's intervention objects as they were, ids included
     */
    public function __construct(
        public string $name,
        public array $interventions,
        public \DateTimeImmutable $savedAt,
    ) {
    }

    public static function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return mb_substr($name, 0, self::MAX_NAME);
    }
}

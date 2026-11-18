<?php

declare(strict_types=1);

namespace App\SpongeCity\Rationale;

/**
 * Why an object is in the plan, as it was placed: the agent's own words for the person, the
 * rules' reasons and warnings, its numbers, and what else was on offer. Who chose it: the agent,
 * or a person who took an alternative instead.
 */
final readonly class Rationale
{
    public const string BY_AGENT = 'agent';
    public const string BY_USER = 'user';
    /** the agent's words are kept to this length */
    public const int MAX_WHY = 600;

    /**
     * @param list<string>         $reasons
     * @param list<string>         $warnings
     * @param array<string, mixed> $metrics
     * @param list<Alternative>    $alternatives
     * @param string|null          $candidate      the proposal's candidate the object is (null: drawn by hand)
     * @param string|null          $agentCandidate the one the agent chose (a person may have taken another since)
     */
    public function __construct(
        public string $objectId,
        public ?string $why,
        public array $reasons = [],
        public array $warnings = [],
        public array $metrics = [],
        public array $alternatives = [],
        public string $by = self::BY_AGENT,
        public ?string $candidate = null,
        public ?string $agentCandidate = null,
    ) {
    }

    /**
     * The object replaced by one of its alternatives: that one is the object now, the one before
     * an alternative; whoever's choice it is.
     *
     * @param array<string, mixed> $before the object as it was
     */
    public function swappedFor(Alternative $taken, string $newObjectId, array $before): self
    {
        $others = array_values(array_filter($this->alternatives, static fn (Alternative $a): bool => $a->id !== $taken->id));
        $previous = new Alternative($this->candidate ?? $this->objectId, $before, $this->metrics, $this->reasons, $this->warnings);

        return new self(
            $newObjectId,
            $this->why,
            $taken->reasons,
            $taken->warnings,
            $taken->metrics,
            [$previous, ...$others],
            $taken->id === $this->agentCandidate ? self::BY_AGENT : self::BY_USER,
            $taken->id,
            $this->agentCandidate,
        );
    }

    public function alternative(string $id): ?Alternative
    {
        foreach ($this->alternatives as $alternative) {
            if ($alternative->id === $id) {
                return $alternative;
            }
        }

        return null;
    }

    public static function clean(?string $why): ?string
    {
        $why = null === $why ? '' : trim(preg_replace('/\s+/u', ' ', $why) ?? '');

        return '' === $why ? null : mb_substr($why, 0, self::MAX_WHY);
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

/**
 * The candidates for one request, best first, and what limited them: kept with the plan until
 * the agent places one (by its id) or asks again.
 */
final readonly class Proposal
{
    /**
     * @param list<Candidate>      $candidates
     * @param array<string, float> $excluded    why the plot's ground was ruled out: reason → share of the plot
     * @param list<string>         $assumptions the rules and data behind the candidates
     */
    public function __construct(
        public string $type,
        public array $candidates,
        public array $excluded,
        public array $assumptions,
        public ?string $note = null,
    ) {
    }

    public function candidate(string $id): ?Candidate
    {
        foreach ($this->candidates as $candidate) {
            if ($candidate->id === $id) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'candidates' => array_map(static fn (Candidate $c): array => $c->toArray(), $this->candidates),
            'excluded_share_of_plot' => $this->excluded,
            'assumptions' => $this->assumptions,
            ...(null === $this->note ? [] : ['note' => $this->note]),
        ];
    }
}

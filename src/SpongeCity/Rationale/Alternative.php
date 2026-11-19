<?php

declare(strict_types=1);

namespace App\SpongeCity\Rationale;

/**
 * A candidate the agent was offered for the same place and did not choose: the object it would
 * have placed, its numbers and the reasons that came with it, kept so a person can compare and
 * swap it in.
 */
final readonly class Alternative
{
    /**
     * @param array<string, mixed> $object  the site object it would place
     * @param array<string, mixed> $metrics as the proposal gave them (area_m2, catchment_m2, …)
     * @param list<string>         $reasons
     * @param list<string>         $warnings
     */
    public function __construct(
        public string $id,
        public array $object,
        public array $metrics,
        public array $reasons = [],
        public array $warnings = [],
    ) {
    }
}

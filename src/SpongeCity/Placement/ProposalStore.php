<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use App\SpongeCity\CacheStore;

/**
 * The latest proposal per plan and intervention type, until a candidate of it is placed or the
 * agent asks again.
 *
 * @extends CacheStore<Proposal>
 */
final readonly class ProposalStore extends CacheStore
{
    private const string KEY_PREFIX = 'spongecity_proposal_';

    protected function type(): string
    {
        return Proposal::class;
    }

    public function find(string $planId, string $type): ?Proposal
    {
        return $this->fetch(self::key($planId, $type));
    }

    public function save(string $planId, Proposal $proposal): void
    {
        $this->store(self::key($planId, $proposal->type), $proposal);
    }

    private static function key(string $planId, string $type): string
    {
        return self::KEY_PREFIX.$planId.'_'.$type;
    }
}

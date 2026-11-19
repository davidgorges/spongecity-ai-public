<?php

declare(strict_types=1);

namespace App\SpongeCity\Conditions;

use App\SpongeCity\CacheStore;

/**
 * The site conditions of each plan, next to the plan in the application cache:
 * imported with the site, read by the 3D view and the agent's tools.
 *
 * @extends CacheStore<SiteConditions>
 */
final readonly class SiteConditionsStore extends CacheStore
{
    private const string KEY_PREFIX = 'spongecity_conditions_';

    protected function type(): string
    {
        return SiteConditions::class;
    }

    public function find(string $planId): ?SiteConditions
    {
        return $this->fetch(self::KEY_PREFIX.$planId);
    }

    public function get(string $planId): SiteConditions
    {
        return $this->find($planId) ?? SiteConditions::none();
    }

    public function save(string $planId, SiteConditions $conditions): void
    {
        $this->store(self::KEY_PREFIX.$planId, $conditions);
    }
}

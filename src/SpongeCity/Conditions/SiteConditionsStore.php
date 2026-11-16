<?php

declare(strict_types=1);

namespace App\SpongeCity\Conditions;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The site conditions of each plan, next to the plan in the application cache:
 * imported with the site, read by the 3D view and the agent's tools.
 */
final readonly class SiteConditionsStore
{
    private const string KEY_PREFIX = 'spongecity_conditions_';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function find(string $planId): ?SiteConditions
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$planId);
        $conditions = $item->get();

        return $item->isHit() && $conditions instanceof SiteConditions ? $conditions : null;
    }

    public function get(string $planId): SiteConditions
    {
        return $this->find($planId) ?? SiteConditions::none();
    }

    public function save(string $planId, SiteConditions $conditions): void
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$planId);
        $item->set($conditions);
        $this->pool->save($item);
    }
}

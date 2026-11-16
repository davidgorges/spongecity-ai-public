<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The real surroundings of each plan, next to the plan in the application cache:
 * imported with the site, read by the 3D view.
 */
final readonly class SurroundingsStore
{
    private const string KEY_PREFIX = 'spongecity_surroundings_';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function find(string $planId): ?Surroundings
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$planId);
        $surroundings = $item->get();

        return $item->isHit() && $surroundings instanceof Surroundings ? $surroundings : null;
    }

    public function save(string $planId, Surroundings $surroundings): void
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$planId);
        $item->set($surroundings);
        $this->pool->save($item);
    }
}

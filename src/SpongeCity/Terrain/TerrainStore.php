<?php

declare(strict_types=1);

namespace App\SpongeCity\Terrain;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The terrain of each plan, next to the plan in the application cache: imported
 * with the site, read by the site editor, the agent's tools and the 3D view.
 */
final readonly class TerrainStore
{
    private const string KEY_PREFIX = 'spongecity_terrain_';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function find(string $planId): ?Terrain
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$planId);
        $terrain = $item->get();

        return $item->isHit() && $terrain instanceof Terrain ? $terrain : null;
    }

    public function save(string $planId, ?Terrain $terrain): void
    {
        if (null === $terrain) {
            $this->pool->deleteItem(self::KEY_PREFIX.$planId);

            return;
        }
        $item = $this->pool->getItem(self::KEY_PREFIX.$planId);
        $item->set($terrain);
        $this->pool->save($item);
    }
}

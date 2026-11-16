<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The latest SimStadt run of each workflow per plan, next to the plan in the application cache.
 */
final readonly class SimulationStore
{
    private const string KEY_PREFIX = 'spongecity_simstadt_';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function find(string $planId, SimStadtWorkflow $workflow): ?Simulation
    {
        $item = $this->pool->getItem(self::key($planId, $workflow));
        $simulation = $item->get();

        return $item->isHit() && $simulation instanceof Simulation ? $simulation : null;
    }

    public function save(Simulation $simulation): void
    {
        $item = $this->pool->getItem(self::key($simulation->planId, $simulation->workflow));
        $item->set($simulation);
        $this->pool->save($item);
    }

    private static function key(string $planId, SimStadtWorkflow $workflow): string
    {
        return self::KEY_PREFIX.$planId.'_'.$workflow->value;
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Plans live in the application cache pool: the browser and the MCP tools of
 * the agent read and write the same plan by its id, in separate requests.
 */
final readonly class PlanStore
{
    private const string KEY_PREFIX = 'spongecity_plan_';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $pool,
    ) {
    }

    public function create(string $title, float $latitude, float $longitude, PlanStatus $status = PlanStatus::Ready): Plan
    {
        $plan = new Plan(Uuid::v7()->toBase32(), $title, $latitude, $longitude, status: $status);
        $this->save($plan);

        return $plan;
    }

    public function find(string $id): ?Plan
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$id);
        $plan = $item->get();

        return $item->isHit() && $plan instanceof Plan ? $plan : null;
    }

    public function get(string $id): Plan
    {
        return $this->find($id) ?? throw new PlanNotFound(sprintf('No plan "%s".', $id));
    }

    public function save(Plan $plan): void
    {
        $item = $this->pool->getItem(self::KEY_PREFIX.$plan->id);
        $item->set($plan);
        $this->pool->save($item);
    }
}

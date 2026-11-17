<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use App\SpongeCity\CacheStore;
use Symfony\Component\Uid\Uuid;

/**
 * Plans live in the application cache pool: the browser and the MCP tools of
 * the agent read and write the same plan by its id, in separate requests.
 *
 * @extends CacheStore<Plan>
 */
final readonly class PlanStore extends CacheStore
{
    private const string KEY_PREFIX = 'spongecity_plan_';

    protected function type(): string
    {
        return Plan::class;
    }

    public function create(string $title, float $latitude, float $longitude, PlanStatus $status = PlanStatus::Ready): Plan
    {
        $plan = new Plan(Uuid::v7()->toBase32(), $title, $latitude, $longitude, status: $status);
        $this->save($plan);

        return $plan;
    }

    public function find(string $id): ?Plan
    {
        return $this->fetch(self::KEY_PREFIX.$id);
    }

    public function get(string $id): Plan
    {
        return $this->find($id) ?? throw new PlanNotFound(sprintf('No plan "%s".', $id));
    }

    public function save(Plan $plan): void
    {
        $this->store(self::KEY_PREFIX.$plan->id, $plan);
    }
}

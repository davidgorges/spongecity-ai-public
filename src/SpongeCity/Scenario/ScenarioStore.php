<?php

declare(strict_types=1);

namespace App\SpongeCity\Scenario;

use App\SpongeCity\CacheStore;

/**
 * The saved scenarios of each plan, next to the plan in the storage pool.
 *
 * @extends CacheStore<Scenarios>
 */
final readonly class ScenarioStore extends CacheStore
{
    private const string KEY_PREFIX = 'spongecity_scenarios_';

    protected function type(): string
    {
        return Scenarios::class;
    }

    public function of(string $planId): Scenarios
    {
        return $this->fetch(self::KEY_PREFIX.$planId) ?? new Scenarios();
    }

    public function save(string $planId, Scenario $scenario): void
    {
        $this->store(self::KEY_PREFIX.$planId, $this->of($planId)->with($scenario));
    }

    public function delete(string $planId, string $name): bool
    {
        $scenarios = $this->of($planId);
        if (null === $scenarios->get($name)) {
            return false;
        }
        $this->store(self::KEY_PREFIX.$planId, $scenarios->without($name));

        return true;
    }
}

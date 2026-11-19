<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

use App\SpongeCity\CacheStore;

/**
 * The latest SimStadt run of each workflow per plan, next to the plan in the application cache.
 *
 * @extends CacheStore<Simulation>
 */
final readonly class SimulationStore extends CacheStore
{
    private const string KEY_PREFIX = 'spongecity_simstadt_';

    protected function type(): string
    {
        return Simulation::class;
    }

    public function find(string $planId, SimStadtWorkflow $workflow): ?Simulation
    {
        return $this->fetch(self::key($planId, $workflow));
    }

    public function save(Simulation $simulation): void
    {
        $this->store(self::key($simulation->planId, $simulation->workflow), $simulation);
    }

    private static function key(string $planId, SimStadtWorkflow $workflow): string
    {
        return self::KEY_PREFIX.$planId.'_'.$workflow->value;
    }
}

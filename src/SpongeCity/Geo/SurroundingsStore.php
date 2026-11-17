<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

use App\SpongeCity\CacheStore;

/**
 * The real surroundings of each plan, next to the plan in the application cache:
 * imported with the site, read by the 3D view.
 *
 * @extends CacheStore<Surroundings>
 */
final readonly class SurroundingsStore extends CacheStore
{
    private const string KEY_PREFIX = 'spongecity_surroundings_';

    protected function type(): string
    {
        return Surroundings::class;
    }

    public function find(string $planId): ?Surroundings
    {
        return $this->fetch(self::KEY_PREFIX.$planId);
    }

    public function save(string $planId, Surroundings $surroundings): void
    {
        $this->store(self::KEY_PREFIX.$planId, $surroundings);
    }
}

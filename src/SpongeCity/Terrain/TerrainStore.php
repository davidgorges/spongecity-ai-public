<?php

declare(strict_types=1);

namespace App\SpongeCity\Terrain;

use App\SpongeCity\CacheStore;

/**
 * The terrain of each plan, next to the plan in the application cache: imported
 * with the site, read by the site editor, the agent's tools and the 3D view.
 *
 * @extends CacheStore<Terrain>
 */
final readonly class TerrainStore extends CacheStore
{
    private const string KEY_PREFIX = 'spongecity_terrain_';

    protected function type(): string
    {
        return Terrain::class;
    }

    public function find(string $planId): ?Terrain
    {
        return $this->fetch(self::KEY_PREFIX.$planId);
    }

    /** none (outside Hamburg) removes the one stored before */
    public function save(string $planId, ?Terrain $terrain): void
    {
        null === $terrain ? $this->remove(self::KEY_PREFIX.$planId) : $this->store(self::KEY_PREFIX.$planId, $terrain);
    }
}

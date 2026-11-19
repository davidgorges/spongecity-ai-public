<?php

declare(strict_types=1);

namespace App\SpongeCity\Rationale;

use App\SpongeCity\CacheStore;

/**
 * Why the objects of a plan were placed (Rationale), one set per plan. An object removed from the
 * plan leaves its rationale behind, unused: the workspace shows rationales of objects that exist.
 *
 * @extends CacheStore<Rationales>
 */
final readonly class RationaleStore extends CacheStore
{
    private const string KEY_PREFIX = 'spongecity_rationales_';

    protected function type(): string
    {
        return Rationales::class;
    }

    public function of(string $planId): Rationales
    {
        return $this->fetch(self::KEY_PREFIX.$planId) ?? new Rationales();
    }

    public function record(string $planId, Rationale $rationale): void
    {
        $this->store(self::KEY_PREFIX.$planId, $this->of($planId)->with($rationale));
    }

    public function forget(string $planId, string $objectId): void
    {
        $this->store(self::KEY_PREFIX.$planId, $this->of($planId)->without($objectId));
    }
}

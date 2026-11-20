<?php

declare(strict_types=1);

namespace App\SpongeCity;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps one kind of object per key in the storage pool: plans and what belongs to them, read and
 * written by the browser, the agent's tools and the workers in separate requests. The pool
 * (config/packages/cache.yaml) is not the application cache: it outlives deploys (Redis in production).
 *
 * @template T of object
 */
abstract readonly class CacheStore
{
    public const string POOL = 'spongecity.storage';

    public function __construct(
        #[Autowire(service: self::POOL)]
        private CacheItemPoolInterface $pool,
    ) {
    }

    /**
     * @return class-string<T>
     */
    abstract protected function type(): string;

    /**
     * @return T|null
     */
    protected function fetch(string $key): ?object
    {
        $item = $this->pool->getItem($key);
        $value = $item->get();
        $type = $this->type();

        return $item->isHit() && $value instanceof $type ? $value : null;
    }

    /**
     * @param T $value
     */
    protected function store(string $key, object $value): void
    {
        $this->pool->save($this->pool->getItem($key)->set($value));
    }

    protected function remove(string $key): void
    {
        $this->pool->deleteItem($key);
    }
}

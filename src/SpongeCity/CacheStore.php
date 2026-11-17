<?php

declare(strict_types=1);

namespace App\SpongeCity;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Keeps one kind of object per key in the application cache pool: plans and what belongs to
 * them, read and written by the browser, the agent's tools and the workers in separate requests.
 *
 * @template T of object
 */
abstract readonly class CacheStore
{
    public function __construct(
        #[Autowire(service: 'cache.app')]
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

<?php

declare(strict_types=1);

namespace Core\Configurator\Definition;

use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * Every ProductDefinition of the application, by key().
 */
final readonly class ProductDefinitionRegistry
{
    /**
     * @param ServiceLocator<ProductDefinition> $definitions
     */
    public function __construct(
        #[AutowireLocator(ProductDefinition::TAG, defaultIndexMethod: 'key')]
        private ServiceLocator $definitions,
    ) {
    }

    public function get(string $key): ProductDefinition
    {
        if (!$this->definitions->has($key)) {
            throw new \InvalidArgumentException(sprintf('Unknown product definition "%s".', $key));
        }

        return $this->definitions->get($key);
    }

    public function has(string $key): bool
    {
        return $this->definitions->has($key);
    }

    /**
     * @return array<string, string> labels by key, for choice lists
     */
    public function labels(): array
    {
        $labels = [];
        foreach (array_keys($this->definitions->getProvidedServices()) as $key) {
            $labels[$key] = $this->get($key)->label();
        }

        return $labels;
    }
}

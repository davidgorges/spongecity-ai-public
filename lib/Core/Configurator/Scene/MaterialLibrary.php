<?php

declare(strict_types=1);

namespace Core\Configurator\Scene;

use Webmozart\Assert\Assert;

final readonly class MaterialLibrary
{
    /**
     * @param array<string, Material> $materials
     */
    public function __construct(private array $materials)
    {
        Assert::allIsInstanceOf($materials, Material::class);
    }

    public function has(string $key): bool
    {
        return isset($this->materials[$key]);
    }

    /**
     * @return array<string, Material>
     */
    public function all(): array
    {
        return $this->materials;
    }
}

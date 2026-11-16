<?php

declare(strict_types=1);

namespace Core\Configurator\Assembly;

use Core\Configurator\Scene\Material;

/**
 * Everything a configuration consists of: the single source for the 3D
 * scene, the bill of materials and the price, so they never disagree.
 *
 * @implements \IteratorAggregate<int, Part>
 */
final class Assembly implements \IteratorAggregate, \Countable
{
    /** @var list<Part> */
    private array $parts = [];

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, bool> */
    private array $environment = [];
    /** @var array<string, Material> */
    private array $materials = [];
    /** @var list<array{float, float, float, float}> */
    private array $clear = [];

    public function add(Part ...$parts): self
    {
        foreach ($parts as $part) {
            $this->parts[] = $part;
        }

        return $this;
    }

    /**
     * A hint for the customer about the construction (e.g. "an extra post was
     * added because the span exceeds 3.5 m").
     */
    public function note(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    /**
     * A hint for the scenery the viewer draws around the product (see
     * Core\Configurator\Scene\Environment), e.g. no garden path for a fence.
     */
    public function environment(string $hint, bool $value): self
    {
        $this->environment[$hint] = $value;

        return $this;
    }

    /**
     * A material only this assembly uses (scenery in a colour the customer
     * chose); the definition's materials() hold the rest.
     */
    public function material(string $key, Material $material): self
    {
        $this->materials[$key] = $material;

        return $this;
    }

    /**
     * @return array<string, Material>
     */
    public function materials(): array
    {
        return $this->materials;
    }

    /**
     * An area the viewer's own garden (hedges, trees, neighbouring houses)
     * leaves free, in plan millimetres: where the customer's site stands.
     */
    public function keepClear(float $minX, float $maxX, float $minZ, float $maxZ): self
    {
        $this->clear[] = [$minX, $maxX, $minZ, $maxZ];

        return $this;
    }

    /**
     * @return list<array{float, float, float, float}> minX, maxX, minZ, maxZ
     */
    public function clearAreas(): array
    {
        return $this->clear;
    }

    /**
     * A new assembly with the parts, notes, materials and environment hints of both
     * (the other's hints win), e.g. a product and its scenery.
     */
    public function with(self $other): self
    {
        $merged = new self();
        $merged->parts = [...$this->parts, ...$other->parts];
        $merged->notes = [...$this->notes, ...$other->notes];
        $merged->environment = [...$this->environment, ...$other->environment];
        $merged->materials = [...$this->materials, ...$other->materials];
        $merged->clear = [...$this->clear, ...$other->clear];

        return $merged;
    }

    /**
     * @return array<string, bool>
     */
    public function environmentHints(): array
    {
        return $this->environment;
    }

    /**
     * @return list<Part>
     */
    public function parts(): array
    {
        return $this->parts;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return \ArrayIterator<int, Part>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->parts);
    }

    public function count(): int
    {
        return count($this->parts);
    }
}

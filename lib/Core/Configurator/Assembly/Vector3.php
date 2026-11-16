<?php

declare(strict_types=1);

namespace Core\Configurator\Assembly;

/**
 * Millimetres (positions, sizes) or radians (rotations). Y is up, X runs along
 * the width, Z away from the building towards the garden.
 */
final readonly class Vector3
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0,
    ) {
    }

    public static function zero(): self
    {
        return new self();
    }

    /**
     * @return array{float, float, float}
     */
    public function toArray(): array
    {
        return [$this->x, $this->y, $this->z];
    }
}

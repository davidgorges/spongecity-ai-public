<?php

declare(strict_types=1);

namespace Core\Configurator\Assembly;

/**
 * A cuboid centred on the part's position: profiles, posts, panels, boards.
 * Covers nearly everything the target industries build from; more shapes
 * (extrusions along a profile, imported GLB models) can implement Shape.
 */
final readonly class Box implements Shape
{
    public const string TYPE = 'box';

    public function __construct(
        public float $width,
        public float $height,
        public float $depth,
    ) {
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function toArray(): array
    {
        return ['size' => [$this->width, $this->height, $this->depth]];
    }
}

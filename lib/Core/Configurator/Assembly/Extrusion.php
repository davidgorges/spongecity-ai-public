<?php

declare(strict_types=1);

namespace Core\Configurator\Assembly;

use Webmozart\Assert\Assert;

/**
 * A 2D profile in the part's local X/Y plane, extruded along local Z and
 * centred on it: gable triangles, custom profiles, mouldings. Millimetres.
 */
final readonly class Extrusion implements Shape
{
    public const string TYPE = 'extrusion';

    /**
     * @param list<array{float, float}> $profile closed outline, counter-clockwise, without repeating the first point
     */
    public function __construct(
        public array $profile,
        public float $depth,
    ) {
        Assert::minCount($profile, 3);
        Assert::greaterThan($depth, 0);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function toArray(): array
    {
        return [
            'profile' => array_map(static fn (array $point): array => [$point[0], $point[1]], $this->profile),
            'depth' => $this->depth,
        ];
    }
}

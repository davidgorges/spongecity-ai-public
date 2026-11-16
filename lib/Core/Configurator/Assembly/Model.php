<?php

declare(strict_types=1);

namespace Core\Configurator\Assembly;

use Webmozart\Assert\Assert;

/**
 * A ready-made 3D model (plants, furniture, …) from a model library: one GLB
 * per library in assets/configurator/models/, one node per variant (imported
 * with app:configurator:import-model).
 *
 * The model is scaled uniformly to fit the given size; dimensions given as 0
 * are free (e.g. only a height). Unlike a Box, a model's part position is the
 * bottom centre: the point on the ground it stands on.
 */
final readonly class Model implements Shape
{
    public const string TYPE = 'model';

    public function __construct(
        public string $library,
        public string $variant,
        public float $width = 0,
        public float $height = 0,
        public float $depth = 0,
    ) {
        Assert::true($width > 0 || $height > 0 || $depth > 0, 'A model needs at least one target dimension.');
    }

    public static function ofHeight(string $library, string $variant, float $height): self
    {
        return new self($library, $variant, height: $height);
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function toArray(): array
    {
        return [
            'library' => $this->library,
            'variant' => $this->variant,
            'size' => [$this->width, $this->height, $this->depth],
        ];
    }
}

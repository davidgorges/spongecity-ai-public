<?php

declare(strict_types=1);

namespace Core\Configurator\Assembly;

/**
 * How a part counts on the bill of materials: as a piece, by its cut length or
 * by its area. Parts with the same SKU and the same measure are one BOM line.
 */
final readonly class Measure
{
    public const string PIECE = 'piece';
    public const string LENGTH = 'length';
    public const string AREA = 'area';

    /**
     * @param int $size cut length in mm (LENGTH), area in mm² (AREA), 0 for pieces
     */
    private function __construct(
        public string $kind,
        public int $size,
    ) {
    }

    public static function piece(): self
    {
        return new self(self::PIECE, 0);
    }

    public static function length(float $millimetres): self
    {
        return new self(self::LENGTH, (int) round($millimetres));
    }

    public static function area(float $width, float $height): self
    {
        return new self(self::AREA, (int) round($width * $height));
    }

    public function key(): string
    {
        return $this->kind.':'.$this->size;
    }
}

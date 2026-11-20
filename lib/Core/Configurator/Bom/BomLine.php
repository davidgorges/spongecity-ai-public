<?php

declare(strict_types=1);

namespace Core\Configurator\Bom;

use Core\Configurator\Assembly\Measure;

final readonly class BomLine
{
    public function __construct(
        public string $sku,
        public string $label,
        public Measure $measure,
        public int $quantity,
    ) {
    }

    /**
     * Total metres (LENGTH), square metres (AREA), cubic metres (VOLUME) or pieces (PIECE) of this line,
     * the amount a unit price applies to.
     */
    public function totalAmount(): float
    {
        return match ($this->measure->kind) {
            Measure::LENGTH => $this->quantity * $this->measure->size / 1_000,
            Measure::AREA => $this->quantity * $this->measure->size / 1_000_000,
            Measure::VOLUME => $this->quantity * $this->measure->size / 1_000_000_000,
            default => (float) $this->quantity,
        };
    }
}

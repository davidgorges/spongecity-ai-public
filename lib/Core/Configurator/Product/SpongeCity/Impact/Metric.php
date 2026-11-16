<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

/**
 * One measurable effect: the value today, with the design, and where the number comes from.
 */
final readonly class Metric
{
    public function __construct(
        public string $key,
        public string $label,
        public string $unit,
        public float $baseline,
        public float $design,
        public string $basis,
        public bool $higherIsBetter = true,
    ) {
    }

    public function delta(): float
    {
        return $this->design - $this->baseline;
    }

    /**
     * @return array{key: string, label: string, unit: string, baseline: float, design: float, delta: float, improved: bool, basis: string}
     */
    public function toArray(): array
    {
        $delta = $this->delta();

        return [
            'key' => $this->key,
            'label' => $this->label,
            'unit' => $this->unit,
            'baseline' => round($this->baseline, 2),
            'design' => round($this->design, 2),
            'delta' => round($delta, 2),
            'improved' => $this->higherIsBetter ? $delta > 0 : $delta < 0,
            'basis' => $this->basis,
        ];
    }
}

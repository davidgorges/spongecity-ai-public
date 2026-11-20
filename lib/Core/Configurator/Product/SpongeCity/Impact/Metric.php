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
        public ?MetricTarget $target = null,
    ) {
    }

    public function delta(): float
    {
        return $this->design - $this->baseline;
    }

    /** how the planned design rates against the target; null where the metric has none (volumes scale with the plot) */
    public function rating(): ?Rating
    {
        return $this->target?->rate($this->design, $this->higherIsBetter);
    }

    /**
     * @return array{key: string, label: string, unit: string, baseline: float, design: float, delta: float, improved: bool, basis: string, higher_is_better: bool, rating: string|null, baseline_rating: string|null, target: array{good: float, poor: float, source: string}|null}
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
            'higher_is_better' => $this->higherIsBetter,
            'rating' => $this->rating()?->value,
            'baseline_rating' => $this->target?->rate($this->baseline, $this->higherIsBetter)->value,
            'target' => null === $this->target ? null : ['good' => $this->target->good, 'poor' => $this->target->poor, 'source' => $this->target->source],
        ];
    }
}

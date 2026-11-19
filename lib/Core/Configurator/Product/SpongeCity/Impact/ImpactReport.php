<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

final readonly class ImpactReport
{
    /**
     * @param list<Metric>         $metrics
     * @param array<string, float> $areasSquareMetres ground area per GroundClass value (design)
     * @param list<string>         $assumptions
     * @param list<string>         $warnings
     */
    public function __construct(
        public float $plotSquareMetres,
        public int $stormMillimetres,
        public array $metrics,
        public array $areasSquareMetres,
        public array $assumptions,
        public array $warnings,
    ) {
    }

    public function metric(string $key): ?Metric
    {
        foreach ($this->metrics as $metric) {
            if ($metric->key === $key) {
                return $metric;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plot_m2' => round($this->plotSquareMetres, 1),
            'design_storm_mm_per_hour' => $this->stormMillimetres,
            'metrics' => array_map(static fn (Metric $metric): array => $metric->toArray(), $this->metrics),
            'ground_m2' => array_map(static fn (float $area): float => round($area, 1), $this->areasSquareMetres),
            'assumptions' => $this->assumptions,
            'warnings' => $this->warnings,
        ];
    }
}

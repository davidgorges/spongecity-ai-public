<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

/**
 * How the plan cools the plot and what happens to its water: today against the plan, and
 * what each kind of measure contributes (the plan with it minus the plan without it).
 */
final readonly class CoolingReport
{
    private const int DECIMALS = 2;

    /**
     * @param array<string, float|string>                                $referenceDay what the day-values assume
     * @param array<string, array{count: int, scenario: CoolingScenario}> $without      per intervention type: how many, and the plan without them
     * @param list<string>                                               $assumptions
     * @param list<string>                                               $warnings
     */
    public function __construct(
        public float $plotSquareMetres,
        public float $groundSquareMetres,
        public array $referenceDay,
        public float $annualPrecipitationMillimetres,
        public CoolingScenario $baseline,
        public CoolingScenario $design,
        public array $without,
        public array $assumptions,
        public array $warnings,
    ) {
    }

    /**
     * What one kind of measure contributes: the plan minus the plan without it.
     *
     * @return array<string, float>
     */
    public function contributionOf(string $type): array
    {
        return isset($this->without[$type]) ? $this->design->minus($this->without[$type]['scenario']) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $round = static fn (array $values): array => array_map(static fn (float $v): float => round($v, self::DECIMALS), $values);
        $measures = [];
        foreach ($this->without as $type => $without) {
            $measures[] = ['type' => $type, 'count' => $without['count'], ...$round($this->contributionOf($type))];
        }

        return [
            'plot_m2' => round($this->plotSquareMetres, 1),
            'open_ground_m2' => round($this->groundSquareMetres, 1),
            'reference_day' => $this->referenceDay,
            'annual_precipitation_mm' => $this->annualPrecipitationMillimetres,
            'baseline' => $round($this->baseline->values),
            'design' => $round($this->design->values),
            'delta' => $round($this->design->minus($this->baseline)),
            'evaporation_by_ground_l_per_day' => ['baseline' => $round($this->baseline->evaporatedBy), 'design' => $round($this->design->evaporatedBy)],
            'measures' => $measures,
            'assumptions' => $this->assumptions,
            'warnings' => $this->warnings,
        ];
    }
}

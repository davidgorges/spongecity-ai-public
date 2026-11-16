<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use App\SpongeCity\PlanEvaluator;
use Core\Configurator\Pricing\QuoteLine;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\ImpactReport;
use Core\Configurator\Site\Site;

/**
 * The balance of a plan: measurable effects and what the measures cost.
 */
final readonly class PlanAnalyzer
{
    public function __construct(
        private PlanEvaluator $evaluator,
        private ImpactCalculator $calculator,
    ) {
    }

    public function impact(Plan $plan): ImpactReport
    {
        return $this->calculator->calculate(Site::fromJson($plan->siteJson()), $plan->storm());
    }

    /**
     * @return array{currency: string, total: float, complete: bool, lines: list<array{sku: string, label: string, amount: float, unit_price: float|null, total: float|null}>}
     */
    public function cost(Plan $plan): array
    {
        $quote = $this->evaluator->evaluate($plan->values)->quote;

        return [
            'currency' => $quote->currency,
            'total' => $quote->total() / 100,
            'complete' => $quote->isComplete(),
            'lines' => array_map(static fn (QuoteLine $line): array => [
                'sku' => $line->bomLine->sku,
                'label' => $line->bomLine->label,
                'amount' => round($line->bomLine->totalAmount(), 1),
                'unit_price' => null === $line->unitPrice ? null : $line->unitPrice / 100,
                'total' => null === $line->total ? null : $line->total / 100,
            ], $quote->lines),
        ];
    }
}

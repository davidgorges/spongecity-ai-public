<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use App\SpongeCity\PlanEvaluator;
use Core\Configurator\Pricing\QuoteLine;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\ImpactReport;
use Core\Configurator\Site\Site;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The balance of a plan: measurable effects and what the measures cost.
 */
final readonly class PlanAnalyzer
{
    /** bump when the calculation changes, so results of the old one are not reused */
    private const int IMPACT_VERSION = 1;
    private const string IMPACT_KEY = 'spongecity_impact_v%d_%s';

    public function __construct(
        private PlanEvaluator $evaluator,
        private ImpactCalculator $calculator,
        private CacheInterface $cache,
    ) {
    }

    /**
     * The balance depends on the site and the storm only: a re-render that changes neither (a zone
     * selected on the map, a panel closed) reads it back instead of computing it again.
     */
    public function impact(Plan $plan): ImpactReport
    {
        $site = $plan->siteJson();
        $storm = $plan->storm();
        $key = sprintf(self::IMPACT_KEY, self::IMPACT_VERSION, hash('xxh128', $storm.'|'.$site));

        return $this->cache->get($key, fn (): ImpactReport => $this->calculator->calculate(Site::fromJson($site), $storm));
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

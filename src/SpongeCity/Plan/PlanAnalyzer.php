<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use App\SpongeCity\PlanEvaluator;
use Core\Configurator\Pricing\QuoteLine;
use Core\Configurator\Product\SpongeCity\Impact\CoolingCalculator;
use Core\Configurator\Product\SpongeCity\Impact\CoolingReport;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\ImpactReport;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The balance of a plan: measurable effects and what the measures cost.
 */
final readonly class PlanAnalyzer
{
    /** bump when the calculation changes, so results of the old one are not reused */
    private const int IMPACT_VERSION = 4;
    private const string IMPACT_KEY = 'spongecity_impact_v%d_%s';
    private const int COOLING_VERSION = 2;
    private const string COOLING_KEY = 'spongecity_cooling_v%d_%s';
    private const int COSTS_VERSION = 1;
    private const string COSTS_KEY = 'spongecity_costs_v%d_%s';
    private const float CENTS_PER_EURO = 100.0;

    public function __construct(
        private PlanEvaluator $evaluator,
        private ImpactCalculator $calculator,
        private CoolingCalculator $cooling,
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
     * Cooling and water of the plan (null without a plot). It surveys the plot once per kind of measure
     * placed, so it is kept like the impact balance: by site and yearly rain.
     */
    public function cooling(Plan $plan, float $annualPrecipitationMm = CoolingCalculator::DEFAULT_ANNUAL_PRECIPITATION_MM): ?CoolingReport
    {
        $site = $plan->siteJson();
        $key = sprintf(self::COOLING_KEY, self::COOLING_VERSION, hash('xxh128', $annualPrecipitationMm.'|'.$site));

        return $this->cache->get($key, fn (): ?CoolingReport => $this->cooling->calculate(Site::fromJson($site), $annualPrecipitationMm));
    }

    /**
     * What each planned measure costs on its own, euros by object id: the product priced with only
     * that measure on the site (and the buildings, for a green roof's roof), so the numbers come
     * from the same price list and bill of quantities as the total.
     *
     * @return array<string, float>
     */
    public function costsByMeasure(Plan $plan): array
    {
        $site = $plan->siteJson();

        return $this->cache->get(sprintf(self::COSTS_KEY, self::COSTS_VERSION, hash('xxh128', $site)), function () use ($plan, $site): array {
            $objects = Site::fromJson($site)->objects;
            $context = array_values(array_filter($objects, static fn (array $o): bool => in_array($o[O::TYPE], [O::PLOT, O::BUILDING], true)));
            $costs = [];
            foreach ($objects as $object) {
                if (in_array($object[O::TYPE], O::INTERVENTIONS, true)) {
                    $alone = (new SiteType())->normalize([SiteType::OBJECTS => [...$context, $object]]);
                    $costs[(string) $object[O::ID]] = $this->evaluator->evaluate([...$plan->values, SiteType::PARAMETER => $alone])->quote->total() / self::CENTS_PER_EURO;
                }
            }

            return $costs;
        });
    }

    /**
     * @return array{currency: string, total: float, complete: bool, lines: list<array{sku: string, label: string, amount: float, unit: string, unit_price: float|null, total: float|null}>}
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
                // pieces (trees, a green roof's set-up) are counted, areas measured
                'unit' => $line->bomLine->measure->kind,
                'unit_price' => null === $line->unitPrice ? null : $line->unitPrice / 100,
                'total' => null === $line->total ? null : $line->total / 100,
            ], $quote->lines),
        ];
    }
}

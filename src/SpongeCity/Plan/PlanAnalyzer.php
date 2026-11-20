<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use App\SpongeCity\Geo\Hamburg\Hamburg;
use App\SpongeCity\PlanEvaluator;
use Core\Configurator\Pricing\Quote;
use Core\Configurator\Pricing\QuoteLine;
use Core\Configurator\Product\SpongeCity\Funding\Applicant;
use Core\Configurator\Product\SpongeCity\Funding\FundingEstimate;
use Core\Configurator\Product\SpongeCity\Funding\FundingProgramme;
use Core\Configurator\Product\SpongeCity\Funding\HamburgFunding;
use Core\Configurator\Product\SpongeCity\Impact\CoolingCalculator;
use Core\Configurator\Product\SpongeCity\Impact\CoolingReport;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator;
use Core\Configurator\Product\SpongeCity\Impact\ImpactReport;
use Core\Configurator\Product\SpongeCity\SpongeCityDefinition;
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
    private const int IMPACT_VERSION = 7;
    private const string IMPACT_KEY = 'spongecity_impact_v%d_%s';
    private const int COOLING_VERSION = 4;
    private const string COOLING_KEY = 'spongecity_cooling_v%d_%s';
    private const int COSTS_VERSION = 2;
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
        return $this->impactOf($plan->siteJson(), $plan->storm());
    }

    /**
     * The balance of any site in a storm: the plan as it is, without one of its measures, or as a
     * saved scenario. Kept by site and storm like the plan's own.
     */
    public function impactOf(string $siteJson, int $storm): ImpactReport
    {
        $key = sprintf(self::IMPACT_KEY, self::IMPACT_VERSION, hash('xxh128', $storm.'|'.$siteJson));

        return $this->cache->get($key, fn (): ImpactReport => $this->calculator->calculate(Site::fromJson($siteJson), $storm));
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
        return array_map(static fn (array $cost): float => $cost['investment'], $this->measureCosts($plan));
    }

    /**
     * The investment and the yearly running costs of each planned measure on its own, euros by object id.
     *
     * @return array<string, array{investment: float, maintenance_per_year: float}>
     */
    public function measureCosts(Plan $plan): array
    {
        $site = $plan->siteJson();

        return $this->cache->get(sprintf(self::COSTS_KEY, self::COSTS_VERSION, hash('xxh128', $site)), function () use ($plan, $site): array {
            $objects = Site::fromJson($site)->objects;
            $context = array_values(array_filter($objects, static fn (array $o): bool => in_array($o[O::TYPE], [O::PLOT, O::BUILDING], true)));
            $costs = [];
            foreach ($objects as $object) {
                if (in_array($object[O::TYPE], O::INTERVENTIONS, true)) {
                    $alone = (new SiteType())->normalize([SiteType::OBJECTS => [...$context, $object]]);
                    $evaluation = $this->evaluator->evaluate([...$plan->values, SiteType::PARAMETER => $alone]);
                    $costs[(string) $object[O::ID]] = [
                        'investment' => $evaluation->quote->total() / self::CENTS_PER_EURO,
                        'maintenance_per_year' => Quote::price($evaluation->bom, SpongeCityDefinition::maintenancePrices())->total() / self::CENTS_PER_EURO,
                    ];
                }
            }

            return $costs;
        });
    }

    /** what cost() says about where the running costs come from */
    public const string MAINTENANCE_BASIS = 'Running costs: indicative yearly care per unit for public open space (GALK / FLL care guidance), averaged over the lifecycle: watering and pruning trees, weeding and clearing silt from rain gardens, sweeping and refilling joints, mowing grass pavers, one or two visits a year to an extensive green roof. Lifecycle total = investment + %d years of running costs, not discounted.';

    /**
     * The investment, line by line, and what keeping the measures working costs a year: both priced
     * against the same bill of quantities, so they cover the same measures. `lines` are the bill's
     * lines (one per SKU and size: four rain gardens of four sizes are four lines); `items` add them
     * up per SKU, for a person to read (four rain gardens, their area and cost together).
     *
     * @return array{currency: string, total: float, complete: bool, maintenance_per_year: float, lifecycle_years: int, lifecycle_total: float, maintenance_basis: string, funding: array<string, mixed>, net_total: float, lines: list<array{sku: string, label: string, amount: float, quantity: int, unit: string, unit_price: float|null, total: float|null, maintenance_per_year: float|null}>, items: list<array{sku: string, count: int, amount: float, unit: string, total: float|null, maintenance_per_year: float|null}>}
     */
    public function cost(Plan $plan): array
    {
        $evaluation = $this->evaluator->evaluate($plan->values);
        $quote = $evaluation->quote;
        $maintenance = Quote::price($evaluation->bom, SpongeCityDefinition::maintenancePrices());
        $yearly = $maintenance->total() / self::CENTS_PER_EURO;
        $years = SpongeCityDefinition::LIFECYCLE_YEARS;
        $funding = FundingEstimate::of($quote, $plan->applicant(), $this->programmesFor($plan));
        $lines = array_map(static fn (QuoteLine $line, QuoteLine $care): array => [
            'sku' => $line->bomLine->sku,
            'label' => $line->bomLine->label,
            'amount' => round($line->bomLine->totalAmount(), 1),
            'quantity' => $line->bomLine->quantity,
            // pieces (trees, a green roof's set-up) are counted, areas measured
            'unit' => $line->bomLine->measure->kind,
            'unit_price' => null === $line->unitPrice ? null : $line->unitPrice / self::CENTS_PER_EURO,
            'total' => null === $line->total ? null : $line->total / self::CENTS_PER_EURO,
            'maintenance_per_year' => null === $care->total ? null : $care->total / self::CENTS_PER_EURO,
        ], $quote->lines, $maintenance->lines);

        return [
            'currency' => $quote->currency,
            'total' => $quote->total() / self::CENTS_PER_EURO,
            'complete' => $quote->isComplete(),
            'maintenance_per_year' => $yearly,
            'lifecycle_years' => $years,
            'lifecycle_total' => ($quote->total() + $years * $maintenance->total()) / self::CENTS_PER_EURO,
            'maintenance_basis' => sprintf(self::MAINTENANCE_BASIS, $years),
            'funding' => $this->fundingSummary($funding),
            'net_total' => ($quote->total() - $funding->total()) / self::CENTS_PER_EURO,
            'lines' => $lines,
            'items' => self::items($lines),
        ];
    }

    /**
     * The bill's lines added up per SKU, in the order the SKUs first appear. A sum is unknown (null)
     * only when none of its lines has a price.
     *
     * @param list<array{sku: string, amount: float, quantity: int, unit: string, total: float|null, maintenance_per_year: float|null}> $lines
     *
     * @return list<array{sku: string, count: int, amount: float, unit: string, total: float|null, maintenance_per_year: float|null}>
     */
    private static function items(array $lines): array
    {
        $sum = static fn (?float $a, ?float $b): ?float => null === $a && null === $b ? null : ($a ?? 0.0) + ($b ?? 0.0);
        $items = [];
        foreach ($lines as $line) {
            $item = $items[$line['sku']] ?? ['sku' => $line['sku'], 'count' => 0, 'amount' => 0.0, 'unit' => $line['unit'], 'total' => null, 'maintenance_per_year' => null];
            $item['count'] += $line['quantity'];
            $item['amount'] = round($item['amount'] + $line['amount'], 1);
            $item['total'] = $sum($item['total'], $line['total']);
            $item['maintenance_per_year'] = $sum($item['maintenance_per_year'], $line['maintenance_per_year']);
            $items[$line['sku']] = $item;
        }

        return array_values($items);
    }

    /**
     * The programmes of the place the plan lies in: Hamburg's, or none.
     *
     * @return list<FundingProgramme>
     */
    public function programmesFor(Plan $plan): array
    {
        return Hamburg::covers($plan->centre()) ? HamburgFunding::programmes() : [];
    }

    /**
     * @return array{applicant: string, total: float, up_to: bool, programmes: list<array<string, mixed>>, note: string}
     */
    private function fundingSummary(FundingEstimate $funding): array
    {
        $unknown = Applicant::Unknown === $funding->applicant;

        return [
            'applicant' => $funding->applicant->value,
            'total' => $funding->total() / self::CENTS_PER_EURO,
            // nobody said who applies: every programme at its highest share
            'up_to' => $unknown,
            'programmes' => array_map(static fn (array $line): array => [
                'key' => $line['programme']->key,
                'name' => $line['programme']->name,
                'publisher' => $line['programme']->publisher,
                'source' => $line['programme']->url,
                'as_of' => $line['programme']->asOf,
                'valid_until' => $line['programme']->validUntil,
                'eligible_cost' => $line['eligible'] / self::CENTS_PER_EURO,
                'share' => $line['share'],
                'amount' => $line['amount'] / self::CENTS_PER_EURO,
                'capped' => $line['capped'],
                'below_minimum' => $line['below_minimum'],
                'conditions' => $line['programme']->conditions,
            ], $funding->lines),
            'note' => [] === $funding->lines
                ? 'No funding programme known for this place and these measures.'
                : ($unknown
                    ? 'Who applies is not known (set_funding_applicant): each programme at its highest share, as "up to". Public owners usually get nothing from these programmes.'
                    : 'An estimate to apply with, not a promise: apply before ordering anything, and check the current guideline (source).'),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator as I;
use Core\Configurator\Product\SpongeCity\Impact\ImpactReport;
use Core\Configurator\Product\SpongeCity\SpongeCityDefinition;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;

/**
 * What each planned measure, and each kind of measure, adds to the plan and what it costs per unit
 * of effect: the plan's balance minus the balance without it (the others stay, so overlaps count
 * once, for the plan as it is), divided by its investment and by its lifecycle cost.
 */
final readonly class ValueForMoney
{
    /** the effects weighed, with the metric they come from and whether a lower value is the gain */
    public const string RETAINED = 'retained_m3';
    public const string SHADE = 'shade_points';
    public const string COOLER = 'cooler_k';
    public const string PERMEABLE = 'permeable_points';
    private const array EFFECTS = [
        self::RETAINED => [I::METRIC_RETENTION, false],
        self::SHADE => [I::METRIC_SHADE, false],
        self::COOLER => [I::METRIC_SURFACE_TEMPERATURE, true],
        self::PERMEABLE => [I::METRIC_PERMEABLE_SHARE, false],
    ];
    /** below this an effect is noise of the raster, not a gain to price */
    private const float MIN_EFFECT = 0.01;
    public const string BASIS = 'Effect of a measure = the plan with everything minus the plan without that measure (the others stay), in the design storm and at 14:00 on a summer day; for a kind of measure, without all of that kind. Effects that overlap (two rain gardens sharing a catchment, two crowns over the same ground) count once for the plan, so the parts need not add up to the whole. Cost per unit = investment (and lifecycle cost: investment + %d years of running costs) / effect; null where the effect is too small to price. A green roof cools the roof and holds back a year\'s rain (calculate_cooling) more than it shows in the design storm and the open ground.';

    public function __construct(private PlanAnalyzer $analyzer)
    {
    }

    /**
     * @return array{storm_mm_per_hour: int, measures: list<array<string, mixed>>, by_type: list<array<string, mixed>>, basis: string}
     */
    public function of(Plan $plan): array
    {
        // normalised like the sites without a measure, so the only difference is the measure
        $siteJson = (new SiteType())->normalize($plan->siteJson());
        $site = Site::fromJson($siteJson);
        $storm = $plan->storm();
        $full = $this->analyzer->impactOf($siteJson, $storm);
        $costs = $this->analyzer->measureCosts($plan);

        $measures = [];
        $byType = [];
        foreach ($site->objects as $object) {
            if (!in_array($object[O::TYPE], O::INTERVENTIONS, true)) {
                continue;
            }
            $id = (string) $object[O::ID];
            $cost = $costs[$id] ?? ['investment' => 0.0, 'maintenance_per_year' => 0.0];
            $without = $this->impactWithout($site, $storm, static fn (array $o): bool => ($o[O::ID] ?? null) === $object[O::ID]);
            $measures[] = ['id' => $id, 'type' => $object[O::TYPE], 'label' => Site::label($object), ...$this->value($full, $without, $cost)];
            $byType[$object[O::TYPE]]['investment'] = ($byType[$object[O::TYPE]]['investment'] ?? 0.0) + $cost['investment'];
            $byType[$object[O::TYPE]]['maintenance_per_year'] = ($byType[$object[O::TYPE]]['maintenance_per_year'] ?? 0.0) + $cost['maintenance_per_year'];
            $byType[$object[O::TYPE]]['count'] = ($byType[$object[O::TYPE]]['count'] ?? 0) + 1;
        }

        $types = [];
        foreach ($byType as $type => $cost) {
            $without = $this->impactWithout($site, $storm, static fn (array $o): bool => $o[O::TYPE] === $type);
            $types[] = ['type' => $type, 'count' => $cost['count'], ...$this->value($full, $without, $cost)];
        }

        return [
            'storm_mm_per_hour' => $storm,
            'measures' => self::cheapestFirst($measures),
            'by_type' => self::cheapestFirst($types),
            'basis' => sprintf(self::BASIS, SpongeCityDefinition::LIFECYCLE_YEARS),
        ];
    }

    /**
     * @param callable(array<string, mixed>): bool $left
     */
    private function impactWithout(Site $site, int $storm, callable $left): ImpactReport
    {
        $objects = array_values(array_filter($site->objects, static fn (array $o): bool => !$left($o)));

        return $this->analyzer->impactOf((new SiteType())->normalize([SiteType::OBJECTS => $objects]), $storm);
    }

    /**
     * @param array{investment: float, maintenance_per_year: float} $cost
     *
     * @return array<string, mixed>
     */
    private function value(ImpactReport $full, ImpactReport $without, array $cost): array
    {
        $lifecycle = $cost['investment'] + SpongeCityDefinition::LIFECYCLE_YEARS * $cost['maintenance_per_year'];
        $effects = [];
        $perEuro = [];
        $perLifecycleEuro = [];
        foreach (self::EFFECTS as $name => [$metric, $lowerIsGain]) {
            $with = $full->metric($metric)?->design ?? 0.0;
            $before = $without->metric($metric)?->design ?? 0.0;
            $effect = $lowerIsGain ? $before - $with : $with - $before;
            $effects[$name] = round($effect, 2);
            $priced = $effect >= self::MIN_EFFECT && $cost['investment'] > 0.0;
            $perEuro['eur_per_'.$name] = $priced ? round($cost['investment'] / $effect) : null;
            $perLifecycleEuro['lifecycle_eur_per_'.$name] = $priced ? round($lifecycle / $effect) : null;
        }

        return [
            'investment' => round($cost['investment'], 2),
            'maintenance_per_year' => round($cost['maintenance_per_year'], 2),
            'lifecycle_cost' => round($lifecycle, 2),
            'effect' => $effects,
            'cost_per_unit' => [...$perEuro, ...$perLifecycleEuro],
        ];
    }

    /**
     * The best value for water first (the least euros per m³ retained), then for shade; what has no
     * priced effect last.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function cheapestFirst(array $rows): array
    {
        $key = static fn (array $row): array => [
            $row['cost_per_unit']['eur_per_'.self::RETAINED] ?? PHP_INT_MAX,
            $row['cost_per_unit']['eur_per_'.self::SHADE] ?? PHP_INT_MAX,
        ];
        usort($rows, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $rows;
    }
}

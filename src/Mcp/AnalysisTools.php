<?php

declare(strict_types=1);

namespace App\Mcp;

use App\SpongeCity\Plan\Plan;
use App\SpongeCity\Plan\PlanAnalyzer;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\Plan\ValueForMoney;
use App\SpongeCity\Scenario\Scenario;
use App\SpongeCity\Scenario\ScenarioStore;
use Core\Configurator\Product\SpongeCity\Impact\ImpactCalculator as I;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Psr\Clock\ClockInterface;

/**
 * Weighing options: what each measure buys per euro, and named scenarios of the plan's measures to
 * compare and go back to.
 */
final readonly class AnalysisTools
{
    public const string TOOL_COMPARE_MEASURES = 'compare_measures';
    public const string TOOL_SAVE_SCENARIO = 'save_scenario';
    public const string TOOL_LIST_SCENARIOS = 'list_scenarios';
    public const string TOOL_RESTORE_SCENARIO = 'restore_scenario';
    public const string TOOL_DELETE_SCENARIO = 'delete_scenario';
    /** the plan as it is, listed with the saved scenarios */
    public const string CURRENT = 'current plan';
    /** what a scenario's summary reports of the balance: metric → its design value */
    private const array SUMMARY_METRICS = [I::METRIC_RETENTION, I::METRIC_PEAK_COEFFICIENT, I::METRIC_PERMEABLE_SHARE, I::METRIC_SHADE, I::METRIC_SURFACE_TEMPERATURE];

    public function __construct(
        private PlanStore $plans,
        private PlanAnalyzer $analyzer,
        private ValueForMoney $value,
        private ScenarioStore $scenarios,
        private SiteEditor $editor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * What each planned measure, and each kind of measure, buys per euro: its effect on the plan (the plan minus the plan without it: retained m³ in the design storm, shade and permeable share in percentage points, open ground cooler in K), its investment, running costs and lifecycle cost, and the euros per unit of each effect (by investment and by lifecycle cost). Sorted by the least euros per m³ retained, then per shade point. Use it when the user asks what is worth it, how to get the most per euro, or what to drop on a smaller budget; then try the options as scenarios (save_scenario, list_scenarios). Quote the basis: overlapping effects count once, so the parts need not add up.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_COMPARE_MEASURES, annotations: new ToolAnnotations(readOnlyHint: true))]
    public function compareMeasures(string $planId): array
    {
        return $this->value->of($this->plans->get($planId));
    }

    /**
     * Saves the plan's planned interventions as they are now under a name ("rain gardens only", "with green roof"), to compare (list_scenarios) and go back to (restore_scenario). A name that exists is replaced; up to 12 per plan, the oldest go first. Returns the summary of the saved scenario.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_SAVE_SCENARIO, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true))]
    public function saveScenario(string $planId, string $name): array
    {
        $plan = $this->plans->get($planId);
        $name = Scenario::cleanName($name);
        if ('' === $name || self::CURRENT === $name) {
            return ['error' => sprintf('Give the scenario a name (not "%s").', self::CURRENT)];
        }
        $scenario = new Scenario($name, self::interventions(Site::fromJson($plan->siteJson())), $this->clock->now());
        $this->scenarios->save($plan->id, $scenario);

        return $this->summary($plan, $name, $scenario->interventions, $scenario->savedAt);
    }

    /**
     * The saved scenarios of the plan side by side with the plan as it is ("current plan"): each with its measures counted by kind, the balance it reaches (retained m³, runoff coefficient, permeable and shade share in %, surface temperature in °C, on today's site and storm) and its cost (investment, running costs per year, lifecycle total, funding and net). Compare them before you recommend one, and quote the numbers from here.
     *
     * @return array{scenarios: list<array<string, mixed>>}
     */
    #[McpTool(name: self::TOOL_LIST_SCENARIOS, annotations: new ToolAnnotations(readOnlyHint: true))]
    public function listScenarios(string $planId): array
    {
        $plan = $this->plans->get($planId);
        $rows = [$this->summary($plan, self::CURRENT, self::interventions(Site::fromJson($plan->siteJson())), null)];
        foreach ($this->scenarios->of($plan->id)->byName as $scenario) {
            $rows[] = $this->summary($plan, $scenario->name, $scenario->interventions, $scenario->savedAt);
        }

        return ['scenarios' => $rows];
    }

    /**
     * Puts a saved scenario's interventions back in place of the planned ones (the city stays); whoever has the plan open sees it at once. Save the current plan first (save_scenario) if it should not be lost. Returns how many interventions the plan has now.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_RESTORE_SCENARIO, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true))]
    public function restoreScenario(string $planId, string $name): array
    {
        $plan = $this->plans->get($planId);
        $scenario = $this->scenarios->of($plan->id)->get(Scenario::cleanName($name));
        if (null === $scenario) {
            return ['error' => sprintf('No scenario "%s": list_scenarios names them.', $name)];
        }

        return ['restored' => $scenario->name, 'interventions' => $this->editor->replaceInterventions($plan->id, $scenario->interventions)];
    }

    /**
     * Deletes a saved scenario by name; the plan stays as it is.
     */
    #[McpTool(name: self::TOOL_DELETE_SCENARIO, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true))]
    public function deleteScenario(string $planId, string $name): bool
    {
        return $this->scenarios->delete($this->plans->get($planId)->id, Scenario::cleanName($name));
    }

    /**
     * @param list<array<string, mixed>> $interventions
     *
     * @return array<string, mixed>
     */
    private function summary(Plan $plan, string $name, array $interventions, ?\DateTimeImmutable $savedAt): array
    {
        $city = array_values(array_filter(Site::fromJson($plan->siteJson())->objects, static fn (array $o): bool => !in_array($o[O::TYPE], O::INTERVENTIONS, true)));
        $siteJson = (new SiteType())->normalize([SiteType::OBJECTS => [...$city, ...$interventions]]);
        // priced like the plan (its storm, its applicant, its place), never saved
        $variant = clone $plan;
        $variant->replaceSite($siteJson);
        $impact = $this->analyzer->impactOf($siteJson, $plan->storm());
        $cost = $this->analyzer->cost($variant);

        $balance = [];
        foreach (self::SUMMARY_METRICS as $key) {
            $metric = $impact->metric($key);
            $balance[$key] = null === $metric ? null : round($metric->design, 2);
        }

        return [
            'name' => $name,
            'saved_at' => $savedAt?->format(\DATE_ATOM),
            'measures' => array_count_values(array_map(static fn (array $o): string => (string) $o[O::TYPE], $interventions)),
            'balance' => $balance,
            'cost' => [
                'investment' => $cost['total'],
                'maintenance_per_year' => $cost['maintenance_per_year'],
                'lifecycle_total' => $cost['lifecycle_total'],
                'funding' => $cost['funding']['total'],
                'funding_up_to' => $cost['funding']['up_to'],
                'net_total' => $cost['net_total'],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function interventions(Site $site): array
    {
        return array_values(array_filter($site->objects, static fn (array $o): bool => in_array($o[O::TYPE], O::INTERVENTIONS, true)));
    }
}

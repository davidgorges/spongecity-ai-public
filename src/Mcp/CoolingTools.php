<?php

declare(strict_types=1);

namespace App\Mcp;

use App\SpongeCity\Plan\PlanAnalyzer;
use App\SpongeCity\Plan\PlanStore;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Cooling and water beyond the design storm: evapotranspiration and the heat it takes up, the
 * surface, air and perceived temperature on a hot day, and the yearly water balance, each with
 * what every kind of measure contributes.
 */
final readonly class CoolingTools
{
    public const string TOOL_COOLING = 'calculate_cooling';
    private const int MIN_PRECIPITATION_MM = 200;
    private const int MAX_PRECIPITATION_MM = 3000;

    public function __construct(
        private PlanStore $plans,
        private PlanAnalyzer $analyzer,
    ) {
    }

    /**
     * Cooling and water of the plan, today ("baseline") against the plan ("design"), with "delta" and per kind of measure ("measures": the plan minus the plan without that kind, e.g. what the new trees alone do). On a hot summer day (30 °C, shade at 14:00): evapotranspiration in litres per day (trees separately) and the heat it takes up (kWh/day, W/m²); the mean surface temperature of the open ground, split into the surfaces' own temperature and the cooling by shade; tree shade share; an indicative near-ground air cooling; the perceived temperature (PET) and the share of open ground under strong or extreme heat stress (VDI 3787). Over a year: runoff, infiltration and evaporation in m³ and % of the rain (DWA-M 102-4 style; compare with the undeveloped reference in the assumptions). annualPrecipitationMm defaults to Hamburg's 790 mm. Use it when the user asks about cooling, evaporation, heat stress, comfort or the yearly water balance; quote the assumptions and warnings, above all that the air temperature is indicative and the surface temperatures already contain evaporation.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_COOLING, annotations: new ToolAnnotations(readOnlyHint: true))]
    public function calculateCooling(string $planId, ?int $annualPrecipitationMm = null): array
    {
        $plan = $this->plans->get($planId);
        $precipitation = null === $annualPrecipitationMm ? null : max(self::MIN_PRECIPITATION_MM, min(self::MAX_PRECIPITATION_MM, $annualPrecipitationMm));
        $report = null === $precipitation ? $this->analyzer->cooling($plan) : $this->analyzer->cooling($plan, (float) $precipitation);

        return $report?->toArray() ?? ['error' => 'No plot loaded: load a site first.'];
    }
}

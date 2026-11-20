<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

/**
 * The climate numbers of one state of the plot (today, with the plan, or the plan without one
 * kind of measure), as CoolingCalculator derives them from a GroundSurvey.
 */
final readonly class CoolingScenario
{
    public const string KEY_EVAPOTRANSPIRATION = 'evapotranspiration_l_per_day';
    public const string KEY_EVAPOTRANSPIRATION_TREES = 'transpiration_trees_l_per_day';
    public const string KEY_LATENT_COOLING = 'evaporative_cooling_kwh_per_day';
    public const string KEY_LATENT_COOLING_POWER = 'evaporative_cooling_w_per_m2';
    public const string KEY_SURFACE_TEMPERATURE = 'surface_temperature_c';
    /** the mean of the open ground's own surface temperatures, as if nothing shaded it: changes with the surfaces only */
    public const string KEY_SURFACE_MATERIAL = 'surface_temperature_unshaded_c';
    /** how much shade lowers the mean surface temperature: KEY_SURFACE_TEMPERATURE = KEY_SURFACE_MATERIAL - this */
    public const string KEY_SHADE_COOLING = 'surface_cooling_by_shade_k';
    /** the roofs' mean surface temperature, green roofs included (roofs are not shaded in the model) */
    public const string KEY_ROOF_TEMPERATURE = 'roof_surface_temperature_c';
    /** the whole plot's mean surface temperature: open ground and roofs, by area */
    public const string KEY_SITE_SURFACE_TEMPERATURE = 'site_surface_temperature_c';
    public const string KEY_GREEN_ROOF = 'green_roof_m2';
    public const string KEY_SHADE = 'shade_share_percent';
    public const string KEY_TREE_SHADE = 'tree_shade_share_percent';
    public const string KEY_CANOPY = 'canopy_cover_percent';
    public const string KEY_EVAPORATING = 'green_and_water_share_percent';
    public const string KEY_AIR_COOLING = 'air_cooling_k';
    public const string KEY_PET = 'perceived_temperature_c';
    public const string KEY_EXTREME_HEAT = 'extreme_heat_stress_share_percent';
    public const string KEY_STRONG_HEAT = 'strong_heat_stress_share_percent';
    public const string KEY_RUNOFF = 'yearly_runoff_m3';
    public const string KEY_INFILTRATION = 'yearly_infiltration_m3';
    public const string KEY_EVAPORATION = 'yearly_evaporation_m3';
    public const string KEY_RUNOFF_SHARE = 'yearly_runoff_percent';
    public const string KEY_INFILTRATION_SHARE = 'yearly_infiltration_percent';
    public const string KEY_EVAPORATION_SHARE = 'yearly_evaporation_percent';

    /**
     * @param array<string, float> $values     KEY_* → value
     * @param array<string, float> $evaporatedBy litres a day per GroundClass value of the open ground
     */
    public function __construct(
        public array $values,
        public array $evaporatedBy,
    ) {
    }

    public function value(string $key): float
    {
        return $this->values[$key] ?? 0.0;
    }

    /**
     * @return array<string, float> KEY_* → this scenario's value minus the other's
     */
    public function minus(self $other): array
    {
        $delta = [];
        foreach ($this->values as $key => $value) {
            $delta[$key] = $value - $other->value($key);
        }

        return $delta;
    }
}

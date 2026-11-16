<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

/**
 * The SimStadt workflows the agent can run on a site's buildings: the tool name
 * (value) and the template the SimStadt image bundles.
 */
enum SimStadtWorkflow: string
{
    case HeatDemand = 'heat_demand';
    case HeatDemandWithShadow = 'heat_demand_with_shadow';
    case PvPotential = 'pv_potential';

    public function template(): string
    {
        return match ($this) {
            self::HeatDemand => 'HeatDemand',
            self::HeatDemandWithShadow => 'HeatDemandWithShadow',
            self::PvPotential => 'PV',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::HeatDemand => 'Yearly space heating and hot water demand per building (DIN V 18599 monthly energy balance, building physics from use and construction year).',
            self::HeatDemandWithShadow => 'Heat demand as above, with the shade neighbouring buildings cast on facades and roofs (slower: about a minute more).',
            self::PvPotential => 'Photovoltaic potential of every roof surface: suitable area, nominal power, yearly yield. The same roofs a green roof would use.',
        };
    }

    /**
     * The CSV columns kept per building: SimStadt's name → the result key.
     *
     * @return array<string, string>
     */
    public function columns(): array
    {
        return match ($this) {
            self::HeatDemand, self::HeatDemandWithShadow => [
                'PrimaryUsageZoneType' => 'use',
                'Year of construction' => 'year_of_construction',
                'Storey number' => 'storeys',
                'Ridge/mean Height' => 'height_m',
                'Heated area' => 'heated_area_m2',
                'Specific space heating demand' => 'specific_heating_kwh_per_m2a',
                'Yearly Heating demand' => 'heating_kwh_per_a',
                'Total Yearly Heating + DHW demand' => 'heating_and_hot_water_kwh_per_a',
            ],
            self::PvPotential => [
                'Roof area for PV' => 'roof_area_for_pv_m2',
                'PV potential nominal power' => 'pv_kwp',
                'PV potential yield' => 'pv_yield_mwh_per_a',
            ],
        };
    }

    /** the CSV column with the building's id */
    public function idColumn(): string
    {
        return self::PvPotential === $this ? 'Building ID' : 'GMLId';
    }

    /** rows are roof surfaces (summed per building) rather than buildings */
    public function perSurface(): bool
    {
        return self::PvPotential === $this;
    }
}

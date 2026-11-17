<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt\Template;

/**
 * The result table of SimStadt's heat demand templates: one row per building.
 */
trait HeatDemandColumns
{
    public function idColumn(): string
    {
        return 'GMLId';
    }

    public function columns(): array
    {
        return [
            'PrimaryUsageZoneType' => 'use',
            'Year of construction' => 'year_of_construction',
            'Storey number' => 'storeys',
            'Ridge/mean Height' => 'height_m',
            'Heated area' => 'heated_area_m2',
            'Specific space heating demand' => 'specific_heating_kwh_per_m2a',
            'Yearly Heating demand' => 'heating_kwh_per_a',
            'Total Yearly Heating + DHW demand' => 'heating_and_hot_water_kwh_per_a',
        ];
    }

    public function perSurface(): bool
    {
        return false;
    }

    public function sortBy(): string
    {
        return 'heating_and_hot_water_kwh_per_a';
    }
}

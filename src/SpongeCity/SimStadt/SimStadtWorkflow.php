<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

/**
 * The SimStadt workflows the agent can run on a site's buildings, by the name the tools use.
 * What SimStadt needs for each is its SimStadtTemplate.
 */
enum SimStadtWorkflow: string
{
    case HeatDemand = 'heat_demand';
    case HeatDemandWithShadow = 'heat_demand_with_shadow';
    case PvPotential = 'pv_potential';

    public function description(): string
    {
        return match ($this) {
            self::HeatDemand => 'Yearly space heating and hot water demand per building (DIN V 18599 monthly energy balance, building physics from use and construction year).',
            self::HeatDemandWithShadow => 'Heat demand as above, with the shade neighbouring buildings cast on facades and roofs (slower: about a minute more).',
            self::PvPotential => 'Photovoltaic potential of every roof surface: suitable area, nominal power, yearly yield. The same roofs a green roof would use.',
        };
    }
}

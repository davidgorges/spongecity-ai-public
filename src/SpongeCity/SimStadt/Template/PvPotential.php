<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt\Template;

use App\SpongeCity\SimStadt\SimStadtTemplate;
use App\SpongeCity\SimStadt\SimStadtWorkflow;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The photovoltaic potential of every roof surface, added up per building.
 */
#[AsTaggedItem(index: SimStadtWorkflow::PvPotential->value)]
final readonly class PvPotential implements SimStadtTemplate
{
    public function name(): string
    {
        return 'PV';
    }

    public function idColumn(): string
    {
        return 'Building ID';
    }

    public function columns(): array
    {
        return [
            'Roof area for PV' => 'roof_area_for_pv_m2',
            'PV potential nominal power' => 'pv_kwp',
            'PV potential yield' => 'pv_yield_mwh_per_a',
        ];
    }

    public function perSurface(): bool
    {
        return true;
    }

    public function sortBy(): string
    {
        return 'pv_yield_mwh_per_a';
    }
}

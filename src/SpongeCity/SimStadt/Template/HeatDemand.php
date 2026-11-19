<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt\Template;

use App\SpongeCity\SimStadt\SimStadtTemplate;
use App\SpongeCity\SimStadt\SimStadtWorkflow;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Heat demand after DIN V 18599, a monthly energy balance per building.
 */
#[AsTaggedItem(index: SimStadtWorkflow::HeatDemand->value)]
final readonly class HeatDemand implements SimStadtTemplate
{
    use HeatDemandColumns;

    public function name(): string
    {
        return 'HeatDemand';
    }
}

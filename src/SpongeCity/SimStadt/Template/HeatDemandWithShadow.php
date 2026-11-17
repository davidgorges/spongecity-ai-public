<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt\Template;

use App\SpongeCity\SimStadt\SimStadtTemplate;
use App\SpongeCity\SimStadt\SimStadtWorkflow;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Heat demand with the shade neighbouring buildings cast on façades and roofs.
 */
#[AsTaggedItem(index: SimStadtWorkflow::HeatDemandWithShadow->value)]
final readonly class HeatDemandWithShadow implements SimStadtTemplate
{
    use HeatDemandColumns;

    public function name(): string
    {
        return 'HeatDemandWithShadow';
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What SimStadt needs and gives for one workflow: the template its image bundles, and how to
 * read the result table it saves. One service per SimStadtWorkflow, tagged with the workflow's
 * value as its index (#[AsTaggedItem]); SimStadtTemplates finds it.
 */
#[AutoconfigureTag]
interface SimStadtTemplate
{
    /** the template's name in SimStadt ("HeatDemand", "PV") */
    public function name(): string;

    /** the column with the building's id */
    public function idColumn(): string;

    /**
     * The columns kept per building: SimStadt's name → the result key.
     *
     * @return array<string, string>
     */
    public function columns(): array;

    /** rows are roof surfaces, added up per building, rather than buildings */
    public function perSurface(): bool;

    /** the result key the buildings are listed by, largest first */
    public function sortBy(): string;
}

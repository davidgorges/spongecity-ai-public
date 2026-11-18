<?php

declare(strict_types=1);

namespace App\Mcp;

use App\SpongeCity\Placement\PlacementRequest;
use App\SpongeCity\Placement\Placements;
use App\SpongeCity\Plan\PlanStore;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;

/**
 * Where an intervention works, worked out by the server instead of drawn by the agent: the
 * agent asks for candidates, weighs their reasons and places one by its id. The geometry, the
 * terrain and the planning rules stay on the server.
 */
final readonly class PlacementTools
{
    public const string TOOL_PROPOSE = 'propose_placements';
    public const string TOOL_PLACE = 'place_candidate';

    public function __construct(
        private PlanStore $plans,
        private Placements $placements,
    ) {
    }

    /**
     * Proposes where an intervention works on the plot, by its planning rules; nothing is placed yet. type: "raingarden" (more types will follow; place the others with add_objects). Candidates sit where the terrain drains sealed ground, clear of buildings (6 m where the garden infiltrates, 2 m lined), tree root zones, paths and other interventions, sized to what drains into them; best first, each with its outline, metrics (area, catchment, storage, retained water in the design storm, infiltration, lined or not, distance to buildings), the reasons and warnings. Optional: areaM2 (the wanted size), nearX/nearZ (plan millimetres: a place the user named; only flow paths within 30 m), count (1 to 5, default 3). When there is no candidate, the note and excluded_share_of_plot say why: tell the user instead of drawing one anyway. Then call place_candidate with the id you chose.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_PROPOSE, annotations: new ToolAnnotations(readOnlyHint: true))]
    public function proposePlacements(string $planId, string $type, ?float $areaM2 = null, ?int $nearX = null, ?int $nearZ = null, int $count = PlacementRequest::DEFAULT_COUNT): array
    {
        $plan = $this->plans->get($planId);
        if (!$this->placements->supports($type)) {
            return ['error' => sprintf('No placement rules for "%s" yet: place it with add_objects.', $type), 'types' => $this->placements->types()];
        }
        $near = null === $nearX || null === $nearZ ? null : [$nearX, $nearZ];

        return $this->placements->propose($plan, new PlacementRequest($type, $areaM2, $near, $count))->toArray();
    }

    /**
     * Places a candidate from the latest propose_placements by its id (e.g. "raingarden-1"), with an optional label. It is checked again against the plan as it is now; if something stands there since, propose again.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_PLACE, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false))]
    public function placeCandidate(string $planId, string $candidateId, ?string $label = null): array
    {
        return $this->placements->place($this->plans->get($planId), $candidateId, $label);
    }
}

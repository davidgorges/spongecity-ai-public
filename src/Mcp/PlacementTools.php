<?php

declare(strict_types=1);

namespace App\Mcp;

use App\SpongeCity\Placement\PlacementRequest;
use App\SpongeCity\Placement\Placements;
use App\SpongeCity\Placement\Zone;
use App\SpongeCity\Plan\PlanStore;
use Core\Configurator\Site\SiteObject as O;
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
    public const string TOOL_ZONES = 'get_zones';
    public const string TOOL_DESCRIBE_ZONE = 'describe_zone';

    public function __construct(
        private PlanStore $plans,
        private Placements $placements,
    ) {
    }

    /**
     * Proposes where an intervention works on the plot, by its planning rules; nothing is placed yet. type "raingarden": basins shaped by the terrain where it drains sealed ground, clear of buildings (6 m where the garden infiltrates, 2 m lined), tree root zones, paths and other interventions, sized to what drains into them (metrics: area, catchment, storage, retained water in the design storm, infiltration, lined, distance to buildings). type "permeable": existing hard surfaces that become permeable, zone by zone (see get_zones): the zone's use decides the material (yard: pavers, car park: grass pavers, quiet area: gravel) or that it stays (access lanes, sports and play areas); per zone its alternatives side by side (a band along the façade, the ground under the trees, the whole zone; metrics: area, kind, zone, shape, runoff removed, slope, alternatives): choose one per zone. Other types: place with add_objects. Optional: areaM2 (the wanted size), nearX/nearZ (plan millimetres: a place the user named; within 30 m), count (1 to 5, default 3: candidates, or zones for permeable). When there is no candidate, the note and excluded_share_of_plot say why: tell the user instead of drawing one anyway. Then call place_candidate with the id you chose.
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
     * The plot's zones, as a landscape architect reads a site: the areas from OpenStreetMap or set by the user (car park, pitch, playground, access lane …) and the open ground between buildings and paths (taken as a yard of unknown ground: source "assumed", surface "unknown"; it may be a lawn or a garden). Each with id, use, surface today, source (map, user, assumed), label, area and outline. Ask the user about the zones that matter (asphalt or lawn? a fire lane? where do the children play?) and record what they say with describe_zone.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_ZONES, annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getZones(string $planId): array
    {
        return ['zones' => array_map(static fn (Zone $zone): array => $zone->toArray(), $this->placements->zones($this->plans->get($planId))), 'uses' => O::AREA_USES, 'surfaces' => O::SURFACES];
    }

    /**
     * Records what a zone is, as the user told you: its use, one of "yard", "parking", "access" (fire lane, deliveries: stays passable), "sport", "play", "quiet" (edges, seating, little traffic), "green" (already open ground); and/or its ground today, one of "asphalt", "concrete" (concrete or paving stones), "gravel", "lawn", "soil". The use decides what the surface may become, and only ground known to be sealed is ever paved. It is the user's from then on and kept when the site is imported again. Never guess: only record what the user said.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_DESCRIBE_ZONE, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true))]
    public function describeZone(string $planId, string $zoneId, ?string $use = null, ?string $surface = null): array
    {
        $id = $this->placements->describeZone($this->plans->get($planId), $zoneId, $use, $surface);

        return null !== $id ? ['set' => true, 'zone' => $id] : ['set' => false, 'error' => sprintf('No zone "%s", or no such use or surface: see get_zones.', $zoneId), 'uses' => O::AREA_USES, 'surfaces' => O::SURFACES];
    }

    /**
     * Places a candidate from the latest propose_placements by its id (e.g. "raingarden-1"), with an optional label. It is checked again against the plan as it is now; if something stands there since, propose again. Give "why": one or two sentences for the user, in their language, on why you chose this candidate over the others (their goals, the trade-off); it is shown over the object in the 3D view together with the alternatives.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_PLACE, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false))]
    public function placeCandidate(string $planId, string $candidateId, ?string $label = null, ?string $why = null): array
    {
        return $this->placements->place($this->plans->get($planId), $candidateId, $label, $why);
    }
}

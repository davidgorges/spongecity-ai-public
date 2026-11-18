<?php

declare(strict_types=1);

namespace App\Mcp;

use App\SpongeCity\Conditions\InfiltrationPotential;
use App\SpongeCity\Conditions\SiteConditions;
use App\SpongeCity\Conditions\SiteConditionsStore;
use App\SpongeCity\Geo\DataSource;
use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\NominatimGeocoder;
use App\SpongeCity\Import\PlanImporter;
use App\SpongeCity\Plan\OverlapsBuilding;
use App\SpongeCity\Plan\PlanStatus;
use App\SpongeCity\Plan\PlanAnalyzer;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\Plan\SiteEditor;
use App\SpongeCity\Terrain\TerrainAnalysis;
use App\SpongeCity\Terrain\TerrainStore;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Product\SpongeCity\SpongeCityParameter;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * The tools the planning agent works with, exposed over MCP: geodata, the
 * scene of the 3D configurator and the environmental calculations. Plans are
 * addressed by id, so the same tools serve the in-app agent and any MCP client.
 */
final readonly class SpongeCityTools
{
    public const string TOOL_SEARCH_PLACE = 'search_place';
    public const string TOOL_LOAD_SITE = 'load_site';
    public const string TOOL_GET_SITE = 'get_site';
    public const string TOOL_LIST_TYPES = 'list_object_types';
    public const string TOOL_ADD = 'add_objects';
    public const string TOOL_UPDATE = 'update_object';
    public const string TOOL_REMOVE = 'remove_object';
    public const string TOOL_CLEAR = 'clear_interventions';
    public const string TOOL_SET_STORM = 'set_design_storm';
    public const string TOOL_IMPACT = 'calculate_impact';
    public const string TOOL_CONDITIONS = 'get_site_conditions';

    private const int MIN_RADIUS_METRES = 30;
    private const int MAX_RADIUS_METRES = 150;
    private const int DEFAULT_RADIUS_METRES = 70;
    private const float MILLIMETRES_PER_METRE = 1000.0;

    public function __construct(
        private NominatimGeocoder $geocoder,
        private PlanStore $plans,
        private SiteEditor $editor,
        private PlanAnalyzer $analyzer,
        private PlanImporter $importer,
        private SiteConditionsStore $conditions,
        private TerrainStore $terrains,
        private TerrainAnalysis $terrainAnalysis,
    ) {
    }

    /**
     * Finds places by name or address (OpenStreetMap Nominatim).
     *
     * @return list<array{label: string, latitude: float, longitude: float}>
     */
    #[McpTool(name: self::TOOL_SEARCH_PLACE, annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: true))]
    public function searchPlace(string $query): array
    {
        return array_map(static fn (GeoPoint $point): array => ['label' => $point->label, 'latitude' => $point->latitude, 'longitude' => $point->longitude], $this->geocoder->search($query));
    }

    /**
     * Starts rebuilding the real surroundings of a point from OpenStreetMap into the plan: the plot (the school yard, park or garden the point lies in; a 40 m square if none is mapped), buildings with heights and existing trees. This runs in the background and takes from a few seconds to a minute: the plan has status "pending", then "importing", until it is "ready" (or "failed"). Poll get_site until its status is "ready" before you plan anything. Replaces the site of the plan, planned interventions are removed. The plan's origin (0, 0) is the given point; x runs east, z south, all in millimetres.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_LOAD_SITE, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true))]
    public function loadSite(string $planId, float $latitude, float $longitude, int $radiusMetres = self::DEFAULT_RADIUS_METRES): array
    {
        $plan = $this->plans->get($planId);
        $radius = max(self::MIN_RADIUS_METRES, min(self::MAX_RADIUS_METRES, $radiusMetres));
        if (!$this->importer->request($plan, new GeoPoint($latitude, $longitude), $radius)) {
            return ['status' => $plan->status->value, 'note' => 'The site is already being imported. Call get_site until its status is "ready".'];
        }

        return ['status' => PlanStatus::Pending->value, 'note' => 'The import runs in the background. Call get_site until its status is "ready".'];
    }

    /**
     * The state of the plan: its plot, existing objects and planned interventions with their plan coordinates (millimetres, x east, z south, origin = the searched point), and the bounds of the plot.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_GET_SITE, annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getSite(string $planId): array
    {
        $plan = $this->plans->get($planId);
        $site = Site::fromJson($plan->siteJson());
        $plots = $site->ofType(O::PLOT);
        $bounds = [] === $plots ? null : $this->bounds(Site::points($plots[0]));

        $objects = [];
        foreach ($site->objects as $object) {
            $isIntervention = in_array($object[O::TYPE], O::INTERVENTIONS, true);
            if (O::BUILDING === $object[O::TYPE] || O::TREE === $object[O::TYPE]) {
                continue; // existing context is summarised below, one line per object would drown the agent
            }
            $objects[] = [...$object, 'intervention' => $isIntervention];
        }

        return [
            'plan' => $plan->id,
            'status' => $plan->status->value,
            'title' => $plan->title,
            'design_storm_mm_per_hour' => $plan->storm(),
            'plot_bounds_mm' => $bounds,
            'objects' => $objects,
            'existing_buildings' => $this->buildingSummary($site),
            'existing_trees' => array_map(static fn (array $tree): array => [O::X => $tree[O::X], O::Z => $tree[O::Z], O::HEIGHT => $tree[O::HEIGHT], 'crown_diameter' => $tree[O::DIAMETER] ?? 0, O::LABEL => $tree[O::LABEL] ?? null], $site->ofType(O::TREE)),
        ];
    }

    /**
     * What official data says about the plot's ground (Hamburg only): the terrain (elevation, relief, slope, which way surface water runs, and low spots where runoff collects: candidate places for rain gardens), the infiltration potential of the Versickerungspotentialkarte as shares of the plot, each class with what it means for planning, and the source, date, licence and limitations of every official layer of the site (buildings, street trees, terrain, infiltration). Use it before you place rain gardens or permeable surfaces: where infiltration is limited or unlikely, plan retention (lined rain gardens with overflow) and say so. The map is a preliminary assessment, never an infiltration rate; do not claim the water will infiltrate. Empty outside Hamburg.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_CONDITIONS, annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getSiteConditions(string $planId): array
    {
        $plan = $this->plans->get($planId);
        $conditions = $this->conditions->get($plan->id);
        $site = Site::fromJson($plan->siteJson());
        $plots = $site->ofType(O::PLOT);
        $shares = [] === $plots ? [] : $conditions->infiltrationShares(Site::points($plots[0]));
        arsort($shares);
        $classes = [];
        foreach ($shares as $potential => $share) {
            $class = InfiltrationPotential::from($potential);
            // the map's own wording for the facility and depth of the areas of this class
            $areas = array_values(array_filter($conditions->infiltration, static fn (array $a): bool => $a[SiteConditions::POTENTIAL] === $potential));
            $classes[] = [
                'potential' => $potential,
                'share_of_plot' => $share,
                'meaning' => $class->guidance(),
                'suitable_facility' => array_values(array_unique(array_column($areas, SiteConditions::FACILITY))),
                'infiltration_depth' => array_values(array_unique(array_column($areas, SiteConditions::DEPTH))),
            ];
        }

        $terrain = $this->terrains->find($plan->id);

        return [
            'terrain' => null === $terrain || [] === $plots ? null : $this->terrainAnalysis->ofPlot($terrain, Site::points($plots[0]), array_map(Site::points(...), $site->ofType(O::BUILDING))),
            'infiltration' => $classes,
            'unmapped_share_of_plot' => round(max(0.0, 1 - array_sum($shares)), 3),
            'sources' => array_map(static fn (DataSource $s): array => $s->toArray(), $conditions->sources),
            'note' => [] === $conditions->sources ? 'No official site data for this plan (outside Hamburg, or imported before official data was used).' : 'Official data; the infiltration map is for preliminary assessment only.',
        ];
    }

    /**
     * The kinds of objects the plan knows, with their fields, ranges and defaults. Interventions are what you may plan.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_LIST_TYPES, annotations: new ToolAnnotations(readOnlyHint: true))]
    public function listObjectTypes(): array
    {
        $types = [];
        foreach (O::TYPES as $type) {
            $types[$type] = [
                'description' => O::DESCRIPTIONS[$type],
                'intervention' => in_array($type, O::INTERVENTIONS, true),
                'fields' => array_map(static fn (array $field): array => match ($field[0]) {
                    O::INT => ['int', 'min' => $field[1], 'max' => $field[2], 'default' => $field[3]],
                    O::ENUM => ['enum', 'values' => $field[1], 'default' => $field[2]],
                    O::HEX => ['color', 'default' => $field[1]],
                    default => ['points', 'min_points' => $field[1]],
                }, O::fields($type)),
            ];
        }

        return ['types' => $types, 'units' => 'millimetres; x east, z south; angles in degrees'];
    }

    /**
     * Plans interventions: adds objects to the 3D scene. Each object needs "type" (one of the interventions: newtree, raingarden, permeable, planting, shadesail) and its fields; outlines are "points": [[x, z], ...] in millimetres inside the plot and outside every building's outline (existing_buildings in get_site). Out-of-range values are corrected, not rejected. An intervention on a building is not added: it comes back in "conflicts" with the building and the overlap; draw it again in the open. Returns the new ids.
     *
     * @param list<array<string, mixed>> $objects
     *
     * @return array{ids: list<string>, rejected: int, conflicts: list<array<string, mixed>>}
     */
    #[McpTool(name: self::TOOL_ADD, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false))]
    public function addObjects(string $planId, array $objects): array
    {
        return $this->editor->add($planId, $objects);
    }

    /**
     * Changes fields of one object by id; the other fields stay. A move that puts an intervention on a building is not made: "updated" is false and "conflict" says which building.
     *
     * @param array<string, mixed> $changes
     *
     * @return array{updated: bool, conflict?: array<string, mixed>}
     */
    #[McpTool(name: self::TOOL_UPDATE, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true))]
    public function updateObject(
        string $planId,
        string $id,
        // a PHP array is advertised as a JSON list by default, but the changes are a map of field → value
        #[Schema(type: 'object', description: 'The fields to change and their new values, e.g. {"facade": "plaster", "color": "#f2efe8"}.', additionalProperties: true)]
        array $changes,
    ): array
    {
        try {
            return ['updated' => $this->editor->update($planId, $id, $changes)];
        } catch (OverlapsBuilding $e) {
            return ['updated' => false, 'conflict' => $e->conflict->toArray()];
        }
    }

    /**
     * Removes one object by id.
     */
    #[McpTool(name: self::TOOL_REMOVE, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true))]
    public function removeObject(string $planId, string $id): bool
    {
        return $this->editor->remove($planId, $id);
    }

    /**
     * Removes every planned intervention and keeps the real city. Returns how many were removed.
     */
    #[McpTool(name: self::TOOL_CLEAR, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, idempotentHint: true))]
    public function clearInterventions(string $planId): int
    {
        return $this->editor->clearInterventions($planId);
    }

    /**
     * Sets the design storm (millimetres of rain within one hour) the calculation uses: 20 is a heavy rain, 40 a cloudburst, 80 an extreme event.
     */
    #[McpTool(name: self::TOOL_SET_STORM, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: true))]
    public function setDesignStorm(string $planId, int $millimetresPerHour): int
    {
        $storm = max(SpongeCityParameter::STORM_MIN, min(SpongeCityParameter::STORM_MAX, $millimetresPerHour));
        $plan = $this->plans->get($planId);
        $plan->setStorm($storm);
        $this->plans->save($plan);

        return $storm;
    }

    /**
     * The measurable balance of the plan today versus with the interventions: permeable share, runoff and retained rainwater in the design storm, shade share, surface temperature, plus the bill of quantities with a cost estimate. Every metric states its basis, the report lists assumptions and warnings: quote them when you explain the impact.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_IMPACT, annotations: new ToolAnnotations(readOnlyHint: true))]
    public function calculateImpact(string $planId): array
    {
        $plan = $this->plans->get($planId);

        return [...$this->analyzer->impact($plan)->toArray(), 'cost' => $this->analyzer->cost($plan)];
    }

    /**
     * @param list<array{0: int|float, 1: int|float}> $points
     *
     * @return array{min_x: int, max_x: int, min_z: int, max_z: int, width_m: float, depth_m: float}
     */
    private function bounds(array $points): array
    {
        $xs = array_column($points, 0);
        $zs = array_column($points, 1);

        return [
            'min_x' => (int) min($xs),
            'max_x' => (int) max($xs),
            'min_z' => (int) min($zs),
            'max_z' => (int) max($zs),
            'width_m' => round((max($xs) - min($xs)) / self::MILLIMETRES_PER_METRE, 1),
            'depth_m' => round((max($zs) - min($zs)) / self::MILLIMETRES_PER_METRE, 1),
        ];
    }

    /**
     * @return list<array{label: string|null, height_mm: int, centre: array{0: int, 1: int}}>
     */
    private function buildingSummary(Site $site): array
    {
        $summary = [];
        $plots = $site->ofType(O::PLOT);
        $plot = [] === $plots ? null : Polygon::bounds(Site::points($plots[0]));
        foreach ($site->ofType(O::BUILDING) as $building) {
            $points = Site::points($building);
            [$minX, $maxX, $minZ, $maxZ] = Polygon::bounds($points);
            // interventions stay on the plot: the outlines that matter are of the buildings on it or touching it
            $onThePlot = null === $plot || ($minX <= $plot[1] && $plot[0] <= $maxX && $minZ <= $plot[3] && $plot[2] <= $maxZ);
            $summary[] = [
                // the id lets a building's look (facade, color) be corrected with update_object when the user knows better
                'id' => $building[O::ID] ?? null,
                'label' => is_string($building[O::LABEL] ?? null) ? $building[O::LABEL] : null,
                'height_mm' => (int) ($building[O::HEIGHT] ?? 0),
                'storeys' => (int) ($building[O::STOREYS] ?? 0),
                O::FACADE => $building[O::FACADE] ?? null,
                O::COLOR => $building[O::COLOR] ?? null,
                'centre' => [(int) round(array_sum(array_column($points, 0)) / count($points)), (int) round(array_sum(array_column($points, 1)) / count($points))],
                ...($onThePlot ? ['outline' => array_map(static fn (array $p): array => [(int) $p[0], (int) $p[1]], $points)] : []),
            ];
        }

        return $summary;
    }
}

<?php

declare(strict_types=1);

namespace App\Mcp;

use App\SpongeCity\Geo\GeoPoint;
use App\SpongeCity\Geo\Hamburg\Hamburg;
use App\SpongeCity\Plan\PlanStore;
use App\SpongeCity\SimStadt\RunSimulation;
use App\SpongeCity\SimStadt\SimStadtWorkflow;
use App\SpongeCity\SimStadt\Simulation;
use App\SpongeCity\SimStadt\SimulationStatus;
use App\SpongeCity\SimStadt\SimulationStore;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Schema\ToolAnnotations;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Building simulations with SimStadt (HFT Stuttgart's urban energy simulation) for the
 * buildings around a plan's site: heat demand, heat demand with shading, PV potential.
 * A run takes from seconds to a couple of minutes, so it is started and then polled,
 * like load_site. Hamburg only: the buildings come from the city's 3D building model.
 */
final readonly class SimStadtTools
{
    public const string TOOL_SIMULATE = 'simulate_buildings';
    public const string TOOL_GET = 'get_building_simulation';
    /** Hamburg's model has no construction year; the agent may set another assumption */
    public const int DEFAULT_YEAR_OF_CONSTRUCTION = 1970;
    private const int MIN_YEAR = 1850;
    private const int MAX_YEAR = 2025;

    public function __construct(
        private PlanStore $plans,
        private SimulationStore $simulations,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * Starts a SimStadt building simulation for the buildings within 100 m of the plan's centre (Hamburg only). workflow: "heat_demand" (yearly heating and hot water demand per building), "heat_demand_with_shadow" (the same with the shade of neighbouring buildings; a minute slower) or "pv_potential" (photovoltaic potential of every roof: area, kWp, yield; the roofs a green roof would also use). Hamburg's 3D model has no construction years: yearOfConstruction is assumed for every building (default 1970) and decides the building physics, so say which year you assumed. Runs in the background; poll get_building_simulation until status is "done" (usually 10 s to 2 min).
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_SIMULATE, annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: true))]
    public function simulateBuildings(string $planId, string $workflow, int $yearOfConstruction = self::DEFAULT_YEAR_OF_CONSTRUCTION): array
    {
        $plan = $this->plans->get($planId);
        $type = SimStadtWorkflow::tryFrom($workflow);
        if (null === $type) {
            return ['error' => sprintf('Unknown workflow "%s".', $workflow), 'workflows' => self::workflows()];
        }
        if (!Hamburg::covers(new GeoPoint($plan->latitude, $plan->longitude))) {
            return ['error' => 'Building simulations need Hamburg\'s 3D building model: this plan lies outside Hamburg.'];
        }
        $current = $this->simulations->find($plan->id, $type);
        if (null !== $current && $current->isActive()) {
            return ['status' => $current->status->value, 'note' => 'This simulation is already running. Poll get_building_simulation.'];
        }
        $year = max(self::MIN_YEAR, min(self::MAX_YEAR, $yearOfConstruction));
        $this->simulations->save(new Simulation($plan->id, $type, $year));
        $this->bus->dispatch(new RunSimulation($plan->id, $type, $year));

        return ['status' => SimulationStatus::Queued->value, 'workflow' => $type->value, 'year_of_construction' => $year, 'note' => 'Runs in the background. Poll get_building_simulation until its status is "done".'];
    }

    /**
     * The latest SimStadt simulation of a workflow for the plan: its status ("queued", "running", "done", "failed"), and when done SimStadt's totals, one row per building (id, use, position x/z in plan millimetres, heated area and heating demand, or roof area, kWp and yield for PV; largest first, at most 40) and the assumptions. Quote these numbers with their assumptions, above all the construction year.
     *
     * @return array<string, mixed>
     */
    #[McpTool(name: self::TOOL_GET, annotations: new ToolAnnotations(readOnlyHint: true))]
    public function getBuildingSimulation(string $planId, string $workflow): array
    {
        $plan = $this->plans->get($planId);
        $type = SimStadtWorkflow::tryFrom($workflow);
        if (null === $type) {
            return ['error' => sprintf('Unknown workflow "%s".', $workflow), 'workflows' => self::workflows()];
        }
        $simulation = $this->simulations->find($plan->id, $type);
        if (null === $simulation) {
            return ['status' => 'none', 'note' => 'Not simulated yet: call simulate_buildings first.'];
        }

        return array_filter([
            'status' => $simulation->status->value,
            'workflow' => $type->value,
            'description' => $type->description(),
            'year_of_construction' => $simulation->yearOfConstruction,
            'requested_at' => $simulation->requestedAt->format(\DateTimeInterface::ATOM),
            'seconds' => $simulation->seconds,
            'totals' => $simulation->summary,
            'buildings' => $simulation->buildings,
            'assumptions' => $simulation->assumptions,
            'error' => $simulation->error,
        ], static fn (mixed $value): bool => null !== $value && [] !== $value);
    }

    /**
     * @return array<string, string>
     */
    private static function workflows(): array
    {
        $workflows = [];
        foreach (SimStadtWorkflow::cases() as $case) {
            $workflows[$case->value] = $case->description();
        }

        return $workflows;
    }
}

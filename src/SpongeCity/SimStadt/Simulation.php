<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

/**
 * One SimStadt run for a plan: queued while it waits for the worker, running, then
 * done with its summary and per-building results, or failed with the reason.
 * The SimulationRun state machine moves its status.
 */
final class Simulation
{
    public private(set) ?float $seconds = null;
    /** @var array<string, string> SimStadt's own totals ("Yearly Heating demand" → "7205388 kWh / a") */
    public private(set) array $summary = [];
    /** @var list<array<string, int|float|string|null>> per building, largest first */
    public private(set) array $buildings = [];
    /** @var list<string> */
    public private(set) array $assumptions = [];
    public private(set) ?string $error = null;

    public function __construct(
        public readonly string $planId,
        public readonly SimStadtWorkflow $workflow,
        public readonly int $yearOfConstruction,
        public readonly \DateTimeImmutable $requestedAt = new \DateTimeImmutable(),
        public private(set) SimulationStatus $status = SimulationStatus::Queued,
    ) {
    }

    /**
     * @internal the marking store of the SimulationRun state machine: apply its transitions instead
     */
    public function setStatus(SimulationStatus $status): void
    {
        $this->status = $status;
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * @param array<string, string>                      $summary
     * @param list<array<string, int|float|string|null>> $buildings
     * @param list<string>                               $assumptions
     */
    public function recordResults(float $seconds, array $summary, array $buildings, array $assumptions): void
    {
        $this->seconds = round($seconds, 1);
        $this->summary = $summary;
        $this->buildings = $buildings;
        $this->assumptions = $assumptions;
    }

    public function recordError(string $error): void
    {
        $this->error = $error;
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [...get_object_vars($this), 'status' => $this->status->value];
    }

    /**
     * Runs stored before the status was an enum keep loading.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->planId = $data['planId'];
        $this->workflow = $data['workflow'];
        $this->yearOfConstruction = $data['yearOfConstruction'];
        $this->requestedAt = $data['requestedAt'];
        $this->status = SimulationStatus::from($data['status']);
        $this->seconds = $data['seconds'] ?? null;
        $this->summary = $data['summary'] ?? [];
        $this->buildings = $data['buildings'] ?? [];
        $this->assumptions = $data['assumptions'] ?? [];
        $this->error = $data['error'] ?? null;
    }
}

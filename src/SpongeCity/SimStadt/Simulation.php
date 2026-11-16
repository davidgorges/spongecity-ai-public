<?php

declare(strict_types=1);

namespace App\SpongeCity\SimStadt;

/**
 * One SimStadt run for a plan: queued while it waits for the worker, running, then
 * done with its summary and per-building results, or failed with the reason.
 */
final readonly class Simulation
{
    public const string STATUS_QUEUED = 'queued';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_DONE = 'done';
    public const string STATUS_FAILED = 'failed';

    /**
     * @param array<string, string>            $summary   SimStadt's own totals ("Yearly Heating demand" → "7205388 kWh / a")
     * @param list<array<string, int|float|string|null>> $buildings per building, largest first
     * @param list<string>                     $assumptions
     */
    public function __construct(
        public string $planId,
        public SimStadtWorkflow $workflow,
        public string $status,
        public int $yearOfConstruction,
        public \DateTimeImmutable $requestedAt,
        public ?float $seconds = null,
        public array $summary = [],
        public array $buildings = [],
        public array $assumptions = [],
        public ?string $error = null,
    ) {
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true);
    }

    public function withStatus(string $status): self
    {
        return new self($this->planId, $this->workflow, $status, $this->yearOfConstruction, $this->requestedAt);
    }

    /**
     * @param array<string, string>                      $summary
     * @param list<array<string, int|float|string|null>> $buildings
     * @param list<string>                               $assumptions
     */
    public function done(float $seconds, array $summary, array $buildings, array $assumptions): self
    {
        return new self($this->planId, $this->workflow, self::STATUS_DONE, $this->yearOfConstruction, $this->requestedAt, round($seconds, 1), $summary, $buildings, $assumptions);
    }

    public function failed(string $error): self
    {
        return new self($this->planId, $this->workflow, self::STATUS_FAILED, $this->yearOfConstruction, $this->requestedAt, error: $error);
    }
}

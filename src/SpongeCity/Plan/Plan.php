<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use Core\Configurator\Product\SpongeCity\SpongeCityParameter;
use Core\Configurator\Site\SiteType;

/**
 * A planning session: where it is and the configuration values of the sponge city definition.
 * Its site is imported in the background, so a plan is pending until the import has finished.
 */
final readonly class Plan
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_READY = 'ready';
    public const string STATUS_FAILED = 'failed';

    /**
     * @param array<string, int|string|bool> $values
     */
    public function __construct(
        public string $id,
        public string $title,
        public float $latitude,
        public float $longitude,
        public array $values = [],
        public string $status = self::STATUS_READY,
    ) {
    }

    /**
     * @param array<string, int|string|bool> $values
     */
    public function withValues(array $values): self
    {
        return new self($this->id, $this->title, $this->latitude, $this->longitude, $values, $this->status);
    }

    public function withStatus(string $status): self
    {
        return new self($this->id, $this->title, $this->latitude, $this->longitude, $this->values, $status);
    }

    public function withLocation(float $latitude, float $longitude): self
    {
        return new self($this->id, $this->title, $latitude, $longitude, $this->values, $this->status);
    }

    public function isReady(): bool
    {
        return self::STATUS_READY === $this->status;
    }

    public function isPending(): bool
    {
        return self::STATUS_PENDING === $this->status;
    }

    public function siteJson(): string
    {
        $site = $this->values[SiteType::PARAMETER] ?? null;

        return is_string($site) ? $site : '';
    }

    public function storm(): int
    {
        $storm = $this->values[SpongeCityParameter::STORM] ?? null;

        return is_int($storm) ? $storm : SpongeCityParameter::STORM_DEFAULT;
    }
}

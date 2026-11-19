<?php

declare(strict_types=1);

namespace App\SpongeCity\Plan;

use App\SpongeCity\Geo\GeoPoint;
use Core\Configurator\Product\SpongeCity\SpongeCityParameter;
use Core\Configurator\Site\SiteType;

/**
 * A planning session: where it is and the configuration values of the sponge city definition.
 * Its site is imported in the background; the PlanImport state machine moves its status.
 */
final class Plan
{
    /**
     * @param array<string, int|string|bool> $values
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public private(set) float $latitude,
        public private(set) float $longitude,
        public private(set) array $values = [],
        public private(set) PlanStatus $status = PlanStatus::Ready,
    ) {
    }

    /**
     * @internal the marking store of the PlanImport state machine: apply its transitions instead
     */
    public function setStatus(PlanStatus $status): void
    {
        $this->status = $status;
    }

    public function isReady(): bool
    {
        return PlanStatus::Ready === $this->status;
    }

    public function isImporting(): bool
    {
        return $this->status->isImporting();
    }

    /** where the plan is, labelled with its title */
    public function centre(): GeoPoint
    {
        return new GeoPoint($this->latitude, $this->longitude, $this->title);
    }

    public function relocate(float $latitude, float $longitude): void
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }

    public function siteJson(): string
    {
        $site = $this->values[SiteType::PARAMETER] ?? null;

        return is_string($site) ? $site : '';
    }

    public function replaceSite(string $siteJson): void
    {
        $this->values[SiteType::PARAMETER] = $siteJson;
    }

    public function storm(): int
    {
        $storm = $this->values[SpongeCityParameter::STORM] ?? null;

        return is_int($storm) ? $storm : SpongeCityParameter::STORM_DEFAULT;
    }

    /** kept within the range the calculation knows */
    public function setStorm(int $millimetresPerHour): void
    {
        $this->values[SpongeCityParameter::STORM] = max(SpongeCityParameter::STORM_MIN, min(SpongeCityParameter::STORM_MAX, $millimetresPerHour));
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'values' => $this->values,
            'status' => $this->status->value,
        ];
    }

    /**
     * Plans stored before the status was an enum keep loading.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->title = $data['title'];
        $this->latitude = $data['latitude'];
        $this->longitude = $data['longitude'];
        $this->values = $data['values'];
        $this->status = PlanStatus::from($data['status']);
    }
}

<?php

declare(strict_types=1);

namespace App\SpongeCity\Geo;

/**
 * The real buildings and streets around a site (from OpenStreetMap), beyond what the
 * site itself holds: context for the 3D view only, not part of the plan or its balance.
 * Plan coordinates in metres (x east, z south).
 *
 * @phpstan-type BuildingShape array{points: list<array{float, float}>, height: float, facade: string, color: string, roof: string, roofColor: string}
 * @phpstan-type RoadShape array{points: list<array{float, float}>, width: float, kind: string}
 */
final readonly class Surroundings
{
    public const string BUILDINGS = 'buildings';
    public const string ROADS = 'roads';
    public const string RADIUS = 'radius';

    /**
     * @param list<BuildingShape> $buildings
     * @param list<RoadShape>     $roads
     */
    public function __construct(
        public float $radius,
        public array $buildings = [],
        public array $roads = [],
    ) {
    }

    public static function none(): self
    {
        return new self(0.0);
    }

    /**
     * @return array{radius: float, buildings: list<BuildingShape>, roads: list<RoadShape>}
     */
    public function toArray(): array
    {
        return [self::RADIUS => $this->radius, self::BUILDINGS => $this->buildings, self::ROADS => $this->roads];
    }
}

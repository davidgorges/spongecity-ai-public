<?php

declare(strict_types=1);

namespace App\SpongeCity\Placement;

use App\SpongeCity\Conditions\InfiltrationPotential;
use App\SpongeCity\Conditions\SiteConditions;
use App\SpongeCity\Terrain\Terrain;
use App\SpongeCity\Terrain\TerrainAnalysis;
use Core\Configurator\Product\SpongeCity\Impact\GroundClass;
use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;
use Core\Configurator\Site\SiteType;

/**
 * Everything a siting strategy reads about one plan: its objects, the open ground, where water
 * runs, the infiltration map and the design storm. Built once per proposal.
 */
final class PlacementContext
{
    private const float MILLIMETRES_PER_METRE = 1000.0;

    /** @var array<int, InfiltrationPotential> per open-ground cell, read on demand */
    private array $infiltration = [];

    /**
     * @param list<array<string, mixed>> $objects  normalised site objects
     * @param list<array<string, mixed>> $lowSpots TerrainAnalysis' low spots of the plot (plan millimetres)
     */
    private function __construct(
        public readonly string $planId,
        public readonly array $objects,
        public readonly OpenGround $ground,
        public readonly ?RunoffModel $runoff,
        public readonly SiteConditions $conditions,
        public readonly array $lowSpots,
        public readonly int $stormMillimetres,
    ) {
    }

    public static function of(string $planId, string $siteJson, ?Terrain $terrain, SiteConditions $conditions, int $stormMillimetres, TerrainAnalysis $analysis): self
    {
        $objects = SiteType::rawObjects((new SiteType())->normalize($siteJson));
        $ofType = static fn (string ...$types): array => array_values(array_filter($objects, static fn (array $o): bool => in_array($o[O::TYPE], $types, true)));
        $metres = static fn (array $outline): array => array_map(static fn (array $p): array => [$p[0] / self::MILLIMETRES_PER_METRE, $p[1] / self::MILLIMETRES_PER_METRE], $outline);

        $plots = $ofType(O::PLOT);
        $plot = [] === $plots ? [] : Site::points($plots[0]);
        $buildings = array_map(Site::points(...), $ofType(O::BUILDING));
        $runoff = null;
        $lowSpots = [];
        if (null !== $terrain && count($plot) >= 3) {
            $surface = is_string($plots[0][O::SURFACE] ?? null) ? $plots[0][O::SURFACE] : O::SURFACE_ASPHALT;
            $runoff = RunoffModel::of(
                $terrain,
                $metres($plot),
                array_map($metres, $buildings),
                array_map(static fn (array $o): array => $metres(Site::points($o)), $ofType(O::RAINGARDEN, O::PERMEABLE, O::PLANTING)),
                array_map(static fn (array $o): array => $metres(Site::points($o)), $ofType(O::RAINGARDEN)),
                GroundClass::fromSurface($surface)->isSealed(),
            );
            $lowSpots = $analysis->ofPlot($terrain, $plot, $buildings)['low_spots'] ?? [];
        }

        return new self($planId, $objects, OpenGround::of($objects), $runoff, $conditions, $lowSpots, $stormMillimetres);
    }

    /** the infiltration class of the map at a cell of the open ground */
    public function infiltrationAt(int $cell): InfiltrationPotential
    {
        if (!isset($this->infiltration[$cell])) {
            [$x, $z] = $this->ground->centre($cell);
            $this->infiltration[$cell] = InfiltrationPotential::Unknown;
            foreach ($this->conditions->infiltration as $area) {
                if (Polygon::contains($area[SiteConditions::POINTS], $x, $z)
                    && [] === array_filter($area[SiteConditions::HOLES] ?? [], static fn (array $hole): bool => Polygon::contains($hole, $x, $z))) {
                    $this->infiltration[$cell] = InfiltrationPotential::from($area[SiteConditions::POTENTIAL]);
                    break;
                }
            }
        }

        return $this->infiltration[$cell];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ofType(string ...$types): array
    {
        return array_values(array_filter($this->objects, static fn (array $o): bool => in_array($o[O::TYPE], $types, true)));
    }
}

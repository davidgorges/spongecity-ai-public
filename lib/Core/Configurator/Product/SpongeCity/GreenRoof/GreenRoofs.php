<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\GreenRoof;

use Core\Configurator\Product\SpongeCity\Impact\Polygon;
use Core\Configurator\Scenery\Building;
use Core\Configurator\Site\BuildingType;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;

/**
 * Which roofs can be planted, by planning rules for retrofitting existing buildings (FLL
 * Dachbegrünungsrichtlinien): only the plot's own buildings, only flat roofs the 3D model
 * measured as flat, each roof level large and wide enough, a 0.5 m gravel strip along the edges.
 * An intensive roof garden weighs 300 kg/m² and more: offered only on solid flat roofs of some
 * size, never on halls or garages. The construction year is unknown, so every roof needs a
 * structural check: the warnings say so.
 */
final class GreenRoofs
{
    /** FLL: a gravel strip along parapets and edges (fire and maintenance) */
    public const float GRAVEL_STRIP_MM = 500.0;
    /** a roof level smaller than this is a stair head or a canopy, not worth a green roof's fixed costs */
    public const float MIN_LEVEL_M2 = 25.0;
    /** narrower roof levels (mean width) are wall tops, glazed bands or railings */
    private const float MIN_LEVEL_WIDTH_MM = 2000.0;
    public const float MIN_INTENSIVE_M2 = 100.0;
    /** one storey this high and this large: a hall (gym, workshop), whose light roof rarely carries much */
    private const float HALL_MIN_HEIGHT_MM = 6000.0;
    private const float HALL_MIN_M2 = 400.0;
    /** a building counts as the plot's own when this share of its footprint lies on the plot */
    private const float ON_PLOT_SHARE = 0.5;
    private const float SQUARE_MILLIMETRES_PER_SQUARE_METRE = 1_000_000.0;
    /** uses whose roofs are not planted: churches (listed, steep), garages (too small, light) */
    private const array EXCLUDED_USES = [BuildingType::Church->value, BuildingType::Garage->value];
    private const array NO_INTENSIVE_USES = [BuildingType::Industrial->value, BuildingType::Garage->value];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>             $building a normalised building object
     * @param list<array{0: float, 1: float}>  $plot     the plot's outline
     */
    public static function assess(array $building, array $plot): GreenRoofAssessment
    {
        $id = (string) ($building[O::ID] ?? '');
        $label = Site::label($building);
        $no = static fn (string $reason): GreenRoofAssessment => new GreenRoofAssessment($id, $label, [], [], [$reason], []);
        $footprint = Site::points($building);
        if (count($footprint) < 3) {
            return $no('The building has no outline.');
        }
        $area = Polygon::area($footprint);
        if (count($plot) < 3 || Polygon::overlapArea($footprint, $plot) < self::ON_PLOT_SHARE * $area) {
            return new GreenRoofAssessment($id, $label, [], [], ['It is not on the plot: only the plot\'s own buildings are planned.'], [], onPlot: false);
        }
        $use = is_string($building[O::USE] ?? null) ? $building[O::USE] : BuildingType::Other->value;
        if (in_array($use, self::EXCLUDED_USES, true)) {
            return $no(BuildingType::Church->value === $use
                ? 'A church: listed and steep roofs as a rule, not planted.'
                : 'A garage: too small and too light a roof for a green roof\'s fixed costs.');
        }
        if (Building::ROOF_FLAT !== ($building[O::ROOF] ?? null) || isset($building[O::MODEL])) {
            return $no('A pitched roof: the 3D model cannot tell its pitch closely enough for a green roof, which needs shear barriers above 15°. Only flat roofs are offered.');
        }

        $levels = self::levels($building);
        if ([] === $levels) {
            return $no(sprintf('No roof level of at least %.0f m² and %.0f m wide: stair heads and canopies only.', self::MIN_LEVEL_M2, self::MIN_LEVEL_WIDTH_MM / 1000));
        }
        $height = (float) ($building[O::HEIGHT] ?? 0);
        $storeys = (int) ($building[O::STOREYS] ?? 0);
        $isHall = BuildingType::Industrial->value === $use || ($storeys <= 1 && $height >= self::HALL_MIN_HEIGHT_MM && $area / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE >= self::HALL_MIN_M2);
        $planted = array_sum(array_map(static fn (RoofLevel $l): float => $l->plantedSquareMetres, $levels));

        $kinds = [O::KIND_EXTENSIVE];
        $reasons = [sprintf('A flat roof (measured in the 3D building model): %.0f m² can be planted inside the 0.5 m gravel strip.', $planted)];
        $warnings = ['Structural check required: the construction year is unknown, and a saturated extensive green roof weighs 100 to 150 kg/m².'];
        if ($isHall) {
            $reasons[] = 'A hall (one tall storey): extensive only, its long-span roof is usually light.';
            $warnings[] = 'Hall roofs (trapezoidal sheet, timber) often carry little more than snow: a lightweight system of about 60 kg/m² may be the only option.';
        } elseif (in_array($use, self::NO_INTENSIVE_USES, true)) {
            $reasons[] = 'Extensive only for this use.';
        } elseif ($planted >= self::MIN_INTENSIVE_M2) {
            $kinds[] = O::KIND_INTENSIVE;
            $reasons[] = sprintf('Large enough for a roof garden (intensive, from %.0f m²), if the roof slab carries it.', self::MIN_INTENSIVE_M2);
            $warnings[] = 'An intensive roof garden weighs 300 kg/m² and more: on an existing building it usually needs reinforcement, plus railings and access.';
        }
        $warnings[] = 'The waterproofing must be root-resistant (FLL); an old membrane is often renewed first, which this estimate does not include.';

        return new GreenRoofAssessment($id, $label, $kinds, $levels, $reasons, $warnings, $isHall);
    }

    /**
     * A building of the site by its id, assessed against the site's plot; null when there is no such building.
     */
    public static function assessIn(Site $site, string $buildingId): ?GreenRoofAssessment
    {
        $plot = $site->plot();
        foreach ($site->ofType(O::BUILDING) as $building) {
            if (($building[O::ID] ?? null) === $buildingId) {
                return self::assess($building, null === $plot ? [] : Site::points($plot));
            }
        }

        return null;
    }

    /**
     * The green roof already planned on a building, if any.
     *
     * @return array<string, mixed>|null
     */
    public static function onBuilding(Site $site, string $buildingId): ?array
    {
        foreach ($site->ofType(O::GREENROOF) as $greenRoof) {
            if (($greenRoof[O::BUILDING_ID] ?? null) === $buildingId) {
                return $greenRoof;
            }
        }

        return null;
    }

    /**
     * The roof levels of a placed green roof: its building's levels as they are now (none when the
     * building is gone or its roof no longer qualifies).
     *
     * @param array<string, mixed> $greenRoof
     *
     * @return list<RoofLevel>
     */
    public static function levelsOf(Site $site, array $greenRoof): array
    {
        $building = self::buildingOf($site, $greenRoof);

        return null === $building || Building::ROOF_FLAT !== ($building[O::ROOF] ?? null) || isset($building[O::MODEL]) ? [] : self::levels($building);
    }

    /**
     * @param array<string, mixed> $greenRoof
     *
     * @return array<string, mixed>|null
     */
    public static function buildingOf(Site $site, array $greenRoof): ?array
    {
        $id = $greenRoof[O::BUILDING_ID] ?? null;
        foreach ($site->ofType(O::BUILDING) as $building) {
            if (null !== $id && ($building[O::ID] ?? null) === $id) {
                return $building;
            }
        }

        return null;
    }

    /**
     * The flat roof levels worth planting, highest first: a level's ground under a higher level
     * is not counted (the top storey stands on it).
     *
     * @param array<string, mixed> $building
     *
     * @return list<RoofLevel>
     */
    public static function levels(array $building): array
    {
        $parts = Site::parts($building);
        if ([] === $parts) {
            $parts = [[O::POINTS => Site::points($building), O::HEIGHT => (float) ($building[O::HEIGHT] ?? 0)]];
        }
        usort($parts, static fn (array $a, array $b): int => $b[O::HEIGHT] <=> $a[O::HEIGHT]);

        $levels = [];
        $higher = [];
        foreach ($parts as $part) {
            $points = $part[O::POINTS];
            $gross = Polygon::area($points);
            $perimeter = Polygon::perimeter($points);
            // mean width of a strip-like outline: twice its area over its perimeter
            if ($perimeter <= 0.0 || 2 * $gross / $perimeter < self::MIN_LEVEL_WIDTH_MM) {
                $higher[] = $points;
                continue;
            }
            $covered = array_sum(array_map(static fn (array $above): float => Polygon::overlapArea($points, $above), $higher));
            $open = max(0.0, $gross - $covered);
            $planted = max(0.0, $open - $perimeter * self::GRAVEL_STRIP_MM);
            $higher[] = $points;
            if ($open / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE < self::MIN_LEVEL_M2 || $planted <= 0.0) {
                continue;
            }
            $levels[] = new RoofLevel(
                $points,
                Polygon::inset($points, self::GRAVEL_STRIP_MM),
                $part[O::HEIGHT],
                $open / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE,
                $planted / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE,
            );
        }

        return $levels;
    }
}

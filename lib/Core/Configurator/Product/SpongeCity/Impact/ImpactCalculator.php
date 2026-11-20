<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

use Core\Configurator\Product\SpongeCity\GreenRoof\GreenRoofs;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;

/**
 * Rainwater retention, shade, permeability and surface heat of a plot, today
 * and with the planned interventions. The plot is rasterised into square
 * cells; every cell takes the top-most surface, so overlaps resolve like in the
 * 3D view. Every number is traceable: ground classes and coefficients are in
 * GroundClass, the rules below are listed in the report's assumptions.
 */
final readonly class ImpactCalculator
{
    private const int SQUARE_MILLIMETRES_PER_SQUARE_METRE = 1_000_000;
    public const string METRIC_PERMEABLE_SHARE = 'permeable_share';
    public const string METRIC_RUNOFF = 'runoff_volume';
    public const string METRIC_RETENTION = 'retained_volume';
    public const string METRIC_PEAK_COEFFICIENT = 'runoff_coefficient';
    public const string METRIC_SHADE = 'shade_share';
    public const string METRIC_SURFACE_TEMPERATURE = 'surface_temperature';

    public const string UNIT_PERCENT = '%';
    public const string UNIT_CUBIC_METRES = 'm³';
    public const string UNIT_CELSIUS = '°C';
    public const string UNIT_NONE = '';

    /** sun at 14:00 on a summer day in central Europe: 52° above the horizon, from the south-west */
    private const float SUN_ELEVATION = 52.0;
    private const float SUN_AZIMUTH_FROM_NORTH = 225.0;
    /** mature canopy radius as a share of the tree height, the crown starts at this share of the height */
    public const float CANOPY_RADIUS_SHARE = 0.28;
    public const float CROWN_BASE_SHARE = 0.35;
    /** rain held on leaves and evaporated, millimetres */
    private const float CANOPY_INTERCEPTION_MM = 2.0;
    /** media storage above the ponding depth of a rain garden, metres of water */
    public const float RAIN_GARDEN_MEDIA_STORAGE = 0.12;
    /** a rain garden should not drain more than this many times its own area */
    public const float MAX_CATCHMENT_RATIO = 10.0;
    /**
     * a rain garden with a gravel trench below (Mulden-Rigolen-Element) takes the runoff of a larger
     * area: DWA-A 138 swales drain 5 to 15 times their area, a trench below puts them at the top of it.
     */
    public const float MAX_CATCHMENT_RATIO_TRENCH = 15.0;
    /** water a gravel trench holds, as a share of its volume (pores of 16/32 gravel, DWA-A 138: 0.30 to 0.35) */
    public const float TRENCH_VOID_SHARE = 0.35;
    /** sealed ground a tree trench (Baumrigole) takes the runoff of, m² per m³ of trench (BlueGreenStreets: 10 to 15 m³ for 100 to 150 m²) */
    public const float TREE_TRENCH_CATCHMENT_M2_PER_M3 = 10.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const float SHADE_RAY_STEP_METRES = 1.5;
    private const float SHADE_RAY_REACH_METRES = 60.0;
    private const int MAX_CELLS = 40000;
    /**
     * Orientation values for planning, not limits of a standard: where a value counts as good and
     * where as poor. Volumes get none, they scale with the plot.
     */
    private const float TARGET_PERMEABLE_GOOD = 50.0;
    private const float TARGET_PERMEABLE_POOR = 25.0;
    private const float TARGET_COEFFICIENT_GOOD = 0.3;
    private const float TARGET_COEFFICIENT_POOR = 0.6;
    private const float TARGET_SHADE_GOOD = 40.0;
    private const float TARGET_SHADE_POOR = 20.0;
    private const float TARGET_TEMPERATURE_GOOD = 35.0;
    private const float TARGET_TEMPERATURE_POOR = 45.0;
    private const float MIN_CELL_METRES = 0.5;

    public function calculate(Site $site, int $stormMillimetres): ImpactReport
    {
        $plot = $site->plot();
        if (null === $plot) {
            return new ImpactReport(0.0, $stormMillimetres, [], [], [], ['No plot loaded: load a site first.']);
        }
        $outline = Site::points($plot);
        $plotSurface = GroundClass::fromSurface(is_string($plot[O::SURFACE] ?? null) ? $plot[O::SURFACE] : O::SURFACE_ASPHALT);

        $grid = $this->grid($outline);
        $baseline = $this->evaluate($this->surveyOn(self::without($site, O::INTERVENTIONS), $outline, $plotSurface, $grid), $stormMillimetres);
        $design = $this->evaluate($this->surveyOn($site, $outline, $plotSurface, $grid), $stormMillimetres);

        $metrics = [
            new Metric(self::METRIC_PERMEABLE_SHARE, 'spongecity.metric.permeable_share', self::UNIT_PERCENT, $baseline['permeable'] * 100, $design['permeable'] * 100,
                'Share of the plot ground (without roofs) known to have a runoff coefficient below 0.7 (DWA-A 138 / DIN 1986-100 classes); ground of unknown surface is not counted as permeable.',
                target: new MetricTarget(self::TARGET_PERMEABLE_GOOD, self::TARGET_PERMEABLE_POOR, 'At least half of the open ground unsealed is a common sponge-city orientation; below a quarter the plot behaves like sealed ground.')),
            new Metric(self::METRIC_RUNOFF, 'spongecity.metric.runoff', self::UNIT_CUBIC_METRES, $baseline['runoff'], $design['runoff'],
                sprintf('Surface runoff in a %d mm/h design storm: sum of area x runoff coefficient x rain depth, minus what rain gardens and canopies hold back.', $stormMillimetres), false),
            new Metric(self::METRIC_RETENTION, 'spongecity.metric.retention', self::UNIT_CUBIC_METRES, $baseline['retained'], $design['retained'],
                'Rain kept on the plot: infiltrated, stored in rain gardens or intercepted by tree crowns.'),
            new Metric(self::METRIC_PEAK_COEFFICIENT, 'spongecity.metric.runoff_coefficient', self::UNIT_NONE, $baseline['psi'], $design['psi'],
                'Mean runoff coefficient of the plot: 1.0 = all rain runs off, 0.0 = all rain stays.', false,
                new MetricTarget(self::TARGET_COEFFICIENT_GOOD, self::TARGET_COEFFICIENT_POOR, 'Up to 0.3 is close to the natural water balance of green ground; from 0.6 the plot drains like a sealed urban site.')),
            new Metric(self::METRIC_SHADE, 'spongecity.metric.shade', self::UNIT_PERCENT, $baseline['shade'] * 100, $design['shade'] * 100,
                sprintf('Share of the open ground in shade at 14:00 on a summer day (sun %d° high, from the south-west): shadows of buildings, tree crowns and shade sails.', (int) self::SUN_ELEVATION),
                target: new MetricTarget(self::TARGET_SHADE_GOOD, self::TARGET_SHADE_POOR, 'Guidance for schoolyards and playgrounds asks for a third to half of the area in shade at midday; below a fifth there is little refuge from the sun.')),
            new Metric(self::METRIC_SURFACE_TEMPERATURE, 'spongecity.metric.surface_temperature', self::UNIT_CELSIUS, $baseline['temperature'], $design['temperature'],
                sprintf('Mean midday surface temperature of the open ground, indicative literature values per surface; in the shade a surface comes close to the air (%d °C): %d %% of what it is warmer in the sun remains.', (int) CoolingCalculator::REFERENCE_AIR_TEMPERATURE, (int) round(GroundClass::SHADED_EXCESS_SHARE * 100)), false,
                new MetricTarget(self::TARGET_TEMPERATURE_GOOD, self::TARGET_TEMPERATURE_POOR, 'Up to 35 °C is what lawn, planting and shaded ground reach; from 45 °C the ground is mostly sunlit paving or asphalt.')),
        ];

        return new ImpactReport(
            // the outline's own area, as the plot map shows it (the grid's sum of cells differs by a few m²)
            Polygon::area($outline) / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE,
            $stormMillimetres,
            $metrics,
            $design['classes'],
            $this->assumptions(),
            array_values(array_unique([...$baseline['warnings'], ...$design['warnings'], ...self::unknownGroundWarnings($design)])),
        );
    }

    /**
     * @param list<array{0: float, 1: float}> $outline
     *
     * @return array{cell: float, cells: list<array{0: float, 1: float}>}
     */
    private function grid(array $outline): array
    {
        [$minX, $maxX, $minZ, $maxZ] = Polygon::bounds($outline);
        $areaM2 = Polygon::area($outline) / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE;
        $cell = max(self::MIN_CELL_METRES, sqrt(max($areaM2, 1.0) / self::MAX_CELLS)) * 1000;
        $cells = [];
        for ($x = $minX + $cell / 2; $x < $maxX; $x += $cell) {
            for ($z = $minZ + $cell / 2; $z < $maxZ; $z += $cell) {
                if (Polygon::contains($outline, $x, $z)) {
                    $cells[] = [$x, $z];
                }
            }
        }

        return ['cell' => $cell, 'cells' => $cells];
    }

    /**
     * The plot's ground as it lies with all objects of the site: classes, shade, crowns. Null without a plot.
     */
    public function survey(Site $site): ?GroundSurvey
    {
        $plot = $site->plot();
        if (null === $plot) {
            return null;
        }
        $outline = Site::points($plot);
        $plotSurface = GroundClass::fromSurface(is_string($plot[O::SURFACE] ?? null) ? $plot[O::SURFACE] : O::SURFACE_ASPHALT);

        return $this->surveyOn($site, $outline, $plotSurface, $this->grid($outline));
    }

    /**
     * The site without the objects of these types (all interventions: the site as it is today).
     *
     * @param list<string> $types
     */
    public static function without(Site $site, array $types): Site
    {
        return new Site(array_values(array_filter($site->objects, static fn (array $o): bool => !in_array($o[O::TYPE], $types, true))));
    }

    /**
     * @param list<array{0: float, 1: float}>                            $outline
     * @param array{cell: float, cells: list<array{0: float, 1: float}>} $grid
     */
    private function surveyOn(Site $site, array $outline, GroundClass $plotSurface, array $grid): GroundSurvey
    {
        $layers = $this->layers($site);
        $occluders = $this->occluders($site);
        $shadeMap = ShadeMap::of(
            Polygon::bounds($outline),
            $occluders['buildings'],
            array_map(static fn (array $t): array => ['x' => $t['x'], 'z' => $t['z'], 'radius' => $t['radius'], 'base' => $t['height'] * self::CROWN_BASE_SHARE, 'top' => $t['height']], $occluders['trees']),
            self::towardsSun(),
            self::SHADE_RAY_REACH_METRES * self::MILLIMETRES_PER_METRE,
        );
        $cellM2 = ($grid['cell'] / 1000) ** 2;

        $areas = [];
        $ponding = 0.0;
        $reach = 0.0;
        foreach ($grid['cells'] as [$x, $z]) {
            $class = $this->classAt($layers, $plotSurface, $x, $z);
            if (GroundClass::RainGarden === $class) {
                $garden = $this->rainGardenAt($layers, $x, $z);
                $ponding += $cellM2 * ($garden['storage'] ?? 0.0) / self::MILLIMETRES_PER_METRE;
                $reach += $cellM2 * (($garden['trench'] ?? 0.0) > 0.0 ? self::MAX_CATCHMENT_RATIO_TRENCH : self::MAX_CATCHMENT_RATIO);
            }
            // a roof's own shade does not matter: only the open ground is looked at
            $shade = $class->isRoof() ? ShadeKind::Sun : $this->shadeAt($shadeMap, $occluders['sails'], $x, $z);
            $areas[$class->value][$shade->value] = ($areas[$class->value][$shade->value] ?? 0.0) + $cellM2;
        }
        // tree trenches store runoff below a tree standing on the plot, like a rain garden without its basin
        foreach ($site->ofType(O::NEWTREE) as $tree) {
            $volume = is_numeric($tree[O::TRENCH_VOLUME] ?? null) ? (float) $tree[O::TRENCH_VOLUME] : 0.0;
            if ($volume > 0.0 && Polygon::contains($outline, (float) $tree[O::X], (float) $tree[O::Z])) {
                $ponding += $volume * self::TRENCH_VOID_SHARE;
                $reach += $volume * self::TREE_TRENCH_CATCHMENT_M2_PER_M3;
            }
        }
        $crowns = array_map(static fn (array $t): array => [
            'm2' => M_PI * ($t['radius'] / 1000) ** 2,
            'on_plot' => Polygon::contains($outline, $t['x'], $t['z']),
        ], $occluders['trees']);

        return new GroundSurvey(count($grid['cells']) * $cellM2, $areas, $crowns, $ponding, $reach, array_sum(array_map(self::wallArea(...), $site->ofType(O::FACADEGREEN))));
    }

    /**
     * @return array{area: float, permeable: float, runoff: float, retained: float, psi: float, shade: float, temperature: float, unknown: float, unknown_m2: float, temperature_if: array<string, float>, classes: array<string, float>, warnings: list<string>}
     */
    private function evaluate(GroundSurvey $survey, int $storm): array
    {
        $rain = $storm / self::MILLIMETRES_PER_METRE;
        $total = $survey->plotSquareMetres;
        $rainTotal = $total * $rain;
        $warnings = [];

        $classes = [];
        $runoff = 0.0;
        foreach (GroundClass::cases() as $class) {
            $area = $survey->area($class);
            if ($area > 0.0) {
                $classes[$class->value] = $area;
                $runoff += $area * $class->runoffCoefficient() * $rain;
            }
        }
        $ground = $survey->groundArea();
        $permeableGround = 0.0;
        $unknownGround = 0.0;
        // the mean temperature as it is counted, and as it would be were the unknown ground each of what it may be
        $temperatureSums = array_fill_keys(['counted', ...array_map(static fn (GroundClass $c): string => $c->value, GroundClass::UNKNOWN_BOUNDS)], 0.0);
        $survey->eachGroundPatch(static function (GroundClass $class, ShadeKind $shade, float $m2) use (&$permeableGround, &$unknownGround, &$temperatureSums): void {
            $permeableGround += $class->isKnown() && !$class->isSealed() ? $m2 : 0.0;
            $unknownGround += $class->isKnown() ? 0.0 : $m2;
            $temperatureSums['counted'] += $m2 * $class->surfaceTemperatureIn($shade->isShaded());
            foreach (GroundClass::UNKNOWN_BOUNDS as $bound) {
                $temperatureSums[$bound->value] += $m2 * ($class->isKnown() ? $class : $bound)->surfaceTemperatureIn($shade->isShaded());
            }
        });

        // rain gardens take the runoff of the sealed area around them, up to their storage and the catchment limit
        $captured = 0.0;
        $rainGardenArea = $survey->area(GroundClass::RainGarden);
        $sealedCatchment = $survey->sealedArea();
        if ($rainGardenArea > 0.0 || $survey->rainGardenPondingCubicMetres > 0.0) {
            $storage = $survey->rainGardenPondingCubicMetres + $rainGardenArea * self::RAIN_GARDEN_MEDIA_STORAGE;
            $reach = $survey->rainGardenReach();
            $reachable = min($sealedCatchment, $reach);
            $captured = min($storage, $reachable * GroundClass::Asphalt->runoffCoefficient() * $rain);
            if ($rainGardenArea > 0.0 && $sealedCatchment > $reach) {
                $warnings[] = sprintf('The rain gardens (%.0f m²) are small for the %.0f m² of sealed surface around them (max. %d times their area drains into one, %d with a gravel trench below).', $rainGardenArea, $sealedCatchment, (int) self::MAX_CATCHMENT_RATIO, (int) self::MAX_CATCHMENT_RATIO_TRENCH);
            }
        }

        // tree crowns hold back a little rain over the ground below them
        $interception = 0.0;
        foreach ($survey->crowns as $crown) {
            $interception += $crown['m2'] * self::CANOPY_INTERCEPTION_MM / self::MILLIMETRES_PER_METRE;
        }
        $interception = min($interception, max(0.0, $runoff - $captured));
        $runoffAfter = max(0.0, $runoff - $captured - $interception);

        return [
            'area' => $total,
            'permeable' => $ground > 0 ? $permeableGround / $ground : 0.0,
            'runoff' => $runoffAfter,
            'retained' => max(0.0, $rainTotal - $runoffAfter),
            'psi' => $rainTotal > 0 ? $runoffAfter / $rainTotal : 0.0,
            'shade' => $ground > 0 ? $survey->shadedGroundArea() / $ground : 0.0,
            'temperature' => $ground > 0 ? $temperatureSums['counted'] / $ground : 0.0,
            'unknown' => $ground > 0 ? $unknownGround / $ground : 0.0,
            'unknown_m2' => $unknownGround,
            'temperature_if' => array_map(static fn (float $sum): float => $ground > 0 ? $sum / $ground : 0.0, array_diff_key($temperatureSums, ['counted' => true])),
            'classes' => $classes,
            'warnings' => $warnings,
        ];
    }

    /**
     * Ground layers from the top: what lies on the plot's own surface, highest priority first.
     *
     * @return list<array{class: GroundClass, polygon: list<array{0: float, 1: float}>, bounds: array{0: float, 1: float, 2: float, 3: float}, storage: float, trench?: float, within?: list<array{0: float, 1: float}>}>
     */
    private function layers(Site $site): array
    {
        $layers = [];
        $add = static function (GroundClass $class, array $object, float $storage = 0.0, float $trench = 0.0) use (&$layers): void {
            foreach (Site::outlinesOf($object) as $polygon) {
                if (count($polygon) >= 3) {
                    $layers[] = ['class' => $class, 'polygon' => $polygon, 'bounds' => Polygon::bounds($polygon), 'storage' => $storage, 'trench' => $trench];
                }
            }
        };
        // order of the groups = priority, the first match at a point wins
        foreach ($site->ofType(O::GREENROOF) as $greenRoof) {
            $this->greenRoofLayers($site, $greenRoof, $layers);
        }
        foreach ($site->objects as $object) {
            match ($object[O::TYPE]) {
                O::BUILDING, O::HOUSE, O::OUTBUILDING => $add(GroundClass::Roof, $object),
                default => null,
            };
        }
        foreach ($site->objects as $object) {
            if (O::POOL === $object[O::TYPE]) {
                $add(GroundClass::Water, $object);
            }
        }
        foreach ($site->objects as $object) {
            match ($object[O::TYPE]) {
                O::RAINGARDEN => $add(GroundClass::RainGarden, $object, ...self::rainGardenWater($object)),
                O::PLANTING => $add(GroundClass::Planting, $object),
                O::COOLSURFACE => $add(GroundClass::CoolPavement, $object),
                O::PERMEABLE => $add(GroundClass::fromPermeableKind(is_string($object[O::KIND] ?? null) ? $object[O::KIND] : ''), $object),
                default => null,
            };
        }
        foreach ($site->objects as $object) {
            if (O::PAVING === $object[O::TYPE]) {
                $add(GroundClass::Paving, $object);
            }
        }
        // the ground areas known from the map or the user, under everything planned on them
        foreach ($site->objects as $object) {
            // an area of unknown ground leaves it to the plot's surface
            if (O::AREA === $object[O::TYPE] && in_array($object[O::SURFACE] ?? null, O::SURFACES, true)) {
                $add(GroundClass::fromSurface(is_string($object[O::SURFACE] ?? null) ? $object[O::SURFACE] : O::SURFACE_ASPHALT), $object);
            }
        }

        return $layers;
    }

    /**
     * A green roof's planted levels, highest first, each followed by its whole outline as plain roof:
     * the gravel strip, and a stair head standing on a lower level, stay roof. The levels come from the
     * map's building parts, which may reach beyond the building's footprint: only the footprint is roof.
     *
     * @param array<string, mixed>                                                                                                                                                                                       $greenRoof
     * @param list<array{class: GroundClass, polygon: list<array{0: float, 1: float}>, bounds: array{0: float, 1: float, 2: float, 3: float}, storage: float, trench?: float, within?: list<array{0: float, 1: float}>}> $layers
     */
    private function greenRoofLayers(Site $site, array $greenRoof, array &$layers): void
    {
        $building = GreenRoofs::buildingOf($site, $greenRoof);
        if (null === $building) {
            return;
        }
        $planted = GreenRoofs::levelsOf($site, $greenRoof);
        $parts = Site::parts($building);
        if ([] === $parts) {
            $parts = [[O::POINTS => Site::points($building), O::HEIGHT => (float) ($building[O::HEIGHT] ?? 0)]];
        }
        usort($parts, static fn (array $a, array $b): int => $b[O::HEIGHT] <=> $a[O::HEIGHT]);
        $footprint = Site::points($building);
        $within = count($footprint) >= 3 ? ['within' => $footprint] : [];
        $class = GroundClass::greenRoof(is_string($greenRoof[O::KIND] ?? null) ? $greenRoof[O::KIND] : O::KIND_EXTENSIVE);
        foreach ($parts as $part) {
            foreach ($planted as $level) {
                if ($level->points === $part[O::POINTS]) {
                    $layers[] = ['class' => $class, 'polygon' => $level->plantedPoints, 'bounds' => Polygon::bounds($level->plantedPoints), 'storage' => 0.0, ...$within];
                }
            }
            $layers[] = ['class' => GroundClass::Roof, 'polygon' => $part[O::POINTS], 'bounds' => Polygon::bounds($part[O::POINTS]), 'storage' => 0.0, ...$within];
        }
    }

    /**
     * @param list<array{class: GroundClass, polygon: list<array{0: float, 1: float}>, bounds: array{0: float, 1: float, 2: float, 3: float}, storage: float, trench?: float, within?: list<array{0: float, 1: float}>}> $layers
     */
    private function classAt(array $layers, GroundClass $plotSurface, float $x, float $z): GroundClass
    {
        foreach ($layers as $layer) {
            // most layers lie elsewhere: their box rules them out without the polygon test
            [$minX, $maxX, $minZ, $maxZ] = $layer['bounds'];
            if ($x >= $minX && $x <= $maxX && $z >= $minZ && $z <= $maxZ && Polygon::contains($layer['polygon'], $x, $z)
                && (!isset($layer['within']) || Polygon::contains($layer['within'], $x, $z))) {
                return $layer['class'];
            }
        }

        return $plotSurface;
    }

    /**
     * The rain garden a point lies in: its storage (ponding plus the water its trench holds,
     * millimetres of water over its area) and its trench's depth.
     *
     * @param list<array{class: GroundClass, polygon: list<array{0: float, 1: float}>, bounds: array{0: float, 1: float, 2: float, 3: float}, storage: float, trench?: float, within?: list<array{0: float, 1: float}>}> $layers
     *
     * @return array{storage: float, trench: float}|null
     */
    private function rainGardenAt(array $layers, float $x, float $z): ?array
    {
        foreach ($layers as $layer) {
            if (GroundClass::RainGarden === $layer['class'] && Polygon::contains($layer['polygon'], $x, $z)) {
                return ['storage' => $layer['storage'], 'trench' => $layer['trench'] ?? 0.0];
            }
        }

        return null;
    }

    /**
     * What a rain garden holds above its planting medium (ponding plus its trench's pores, millimetres
     * of water) and its trench's depth.
     *
     * @param array<string, mixed> $object
     *
     * @return array{0: float, 1: float}
     */
    private static function rainGardenWater(array $object): array
    {
        $ponding = is_numeric($object[O::STORAGE] ?? null) ? (float) $object[O::STORAGE] : 0.0;
        $trench = is_numeric($object[O::TRENCH] ?? null) ? (float) $object[O::TRENCH] : 0.0;

        return [$ponding + $trench * self::TRENCH_VOID_SHARE, $trench];
    }

    /**
     * Metres of water a rain garden holds per m² of its area: its ponding, its planting medium and
     * the pores of its gravel trench. One formula for the balance, the placement and the panel.
     */
    public static function rainGardenStorageMetres(float $pondingMm, float $trenchMm = 0.0): float
    {
        return ($pondingMm + $trenchMm * self::TRENCH_VOID_SHARE) / self::MILLIMETRES_PER_METRE + self::RAIN_GARDEN_MEDIA_STORAGE;
    }

    /**
     * @return array{buildings: list<array{polygon: list<array{0: float, 1: float}>, height: float}>, trees: list<array{x: float, z: float, radius: float, height: float}>, sails: list<array{polygon: list<array{0: float, 1: float}>, height: float}>}
     */
    private function occluders(Site $site): array
    {
        $buildings = [];
        $trees = [];
        $sails = [];
        foreach ($site->objects as $object) {
            $num = static fn (string $key): float => is_numeric($object[$key] ?? null) ? (float) $object[$key] : 0.0;
            switch ($object[O::TYPE]) {
                case O::BUILDING:
                    // its roof levels cast their own shadows (a low wing less than the tower beside it)
                    $parts = Site::parts($object);
                    foreach ([] === $parts ? [[O::POINTS => Site::points($object), O::HEIGHT => $num(O::HEIGHT)]] : $parts as $part) {
                        $buildings[] = ['polygon' => $part[O::POINTS], 'height' => $part[O::HEIGHT]];
                    }
                    break;
                case O::HOUSE:
                    foreach (Site::outlinesOf($object) as $polygon) {
                        $buildings[] = ['polygon' => $polygon, 'height' => $num(O::STOREYS) * 2800 + 400];
                    }
                    break;
                case O::OUTBUILDING:
                    foreach (Site::outlinesOf($object) as $polygon) {
                        $buildings[] = ['polygon' => $polygon, 'height' => $num(O::HEIGHT)];
                    }
                    break;
                case O::TREE:
                case O::NEWTREE:
                    // a recorded crown (tree register) wins over the estimate from the height
                    $crown = $num(O::DIAMETER);
                    $trees[] = ['x' => $num(O::X), 'z' => $num(O::Z), 'radius' => $crown > 0 ? $crown / 2 : $num(O::HEIGHT) * self::CANOPY_RADIUS_SHARE, 'height' => $num(O::HEIGHT)];
                    break;
                case O::SHADESAIL:
                    $sails[] = ['polygon' => Site::points($object), 'height' => $num(O::Y)];
                    break;
            }
        }

        return ['buildings' => $buildings, 'trees' => $trees, 'sails' => $sails];
    }

    /**
     * What shades a cell: the first a ray towards the sun meets of a sail, a building or a tree crown.
     *
     * @param list<array{polygon: list<array{0: float, 1: float}>, height: float}> $sails
     */
    private function shadeAt(ShadeMap $map, array $sails, float $x, float $z): ShadeKind
    {
        [$dx, $dz] = self::towardsSun();
        $tan = tan(deg2rad(self::SUN_ELEVATION));

        foreach ($sails as $sail) {
            $d = $sail['height'] / $tan;
            if (Polygon::contains($sail['polygon'], $x + $dx * $d, $z + $dz * $d)) {
                return ShadeKind::Sail;
            }
        }
        for ($d = self::SHADE_RAY_STEP_METRES; $d <= self::SHADE_RAY_REACH_METRES; $d += self::SHADE_RAY_STEP_METRES) {
            $meters = $d * self::MILLIMETRES_PER_METRE;
            $rayHeight = $meters * $tan;
            // above everything there is: nothing further along shades it
            if ($rayHeight > $map->tallest()) {
                return ShadeKind::Sun;
            }
            $blocker = $map->blocker($x + $dx * $meters, $z + $dz * $meters, $rayHeight);
            if (null !== $blocker) {
                return $blocker;
            }
        }

        return ShadeKind::Sun;
    }

    /**
     * m² of wall a façade greening covers: the length of its line along the wall times its height.
     *
     * @param array<string, mixed> $object
     */
    public static function wallArea(array $object): float
    {
        $points = Site::points($object);
        $length = 0.0;
        for ($i = 1, $n = count($points); $i < $n; ++$i) {
            $length += hypot($points[$i][0] - $points[$i - 1][0], $points[$i][1] - $points[$i - 1][1]);
        }

        return $length * (is_numeric($object[O::HEIGHT] ?? null) ? (float) $object[O::HEIGHT] : 0.0) / self::SQUARE_MILLIMETRES_PER_SQUARE_METRE;
    }

    /**
     * Where the shadow of a tree's crown falls at the moment the shade is measured: the centre of the
     * crown's shadow on the ground, plan millimetres (the crown's middle height cast away from the sun).
     *
     * @return array{0: float, 1: float}
     */
    public static function crownShadowCentre(float $x, float $z, float $height): array
    {
        [$dx, $dz] = self::towardsSun();
        $middle = $height * (self::CROWN_BASE_SHARE + 1.0) / 2;
        $reach = $middle / tan(deg2rad(self::SUN_ELEVATION));

        return [$x - $dx * $reach, $z - $dz * $reach];
    }

    /**
     * @return array{0: float, 1: float} the unit vector towards the sun in plan (x east, z south: north is -z)
     */
    private static function towardsSun(): array
    {
        $azimuth = deg2rad(self::SUN_AZIMUTH_FROM_NORTH);

        return [sin($azimuth), -cos($azimuth)];
    }

    /**
     * How far the surface temperature could go either way, when part of the open ground is of
     * unknown surface: so nobody takes the halfway value for a measurement.
     *
     * @param array{unknown: float, unknown_m2: float, temperature_if: array<string, float>} $design
     *
     * @return list<string>
     */
    private static function unknownGroundWarnings(array $design): array
    {
        if ($design['unknown_m2'] <= 0.0) {
            return [];
        }
        $range = implode(', ', array_map(static fn (string $class, float $t): string => sprintf('all %s %.1f °C', $class, $t), array_keys($design['temperature_if']), $design['temperature_if']));

        $bounds = implode(' and ', array_map(static fn (GroundClass $c): string => $c->value, GroundClass::UNKNOWN_BOUNDS));

        return [sprintf('%.0f m² of the open ground (%d %%) is of unknown surface, counted halfway between %s: the surface temperature could be from %s. Ask the user what that ground is and record it with describe_zone.', $design['unknown_m2'], (int) round($design['unknown'] * 100), $bounds, $range)];
    }

    /**
     * @return list<string>
     */
    private function assumptions(): array
    {
        return [
            'Runoff coefficients per surface follow typical DWA-A 138 / DIN 1986-100 values (asphalt 0.9, roof 0.9, permeable pavers 0.4, gravel 0.6, lawn 0.15).',
            sprintf('Rain gardens are connected to the sealed surface around them; they hold ponding depth plus %.2f m of water in the planting medium, and %d %% of the volume of a gravel trench below them. Tree trenches hold %d %% of their volume and take the runoff of %d m² per m³.', self::RAIN_GARDEN_MEDIA_STORAGE, (int) round(self::TRENCH_VOID_SHARE * 100), (int) round(self::TRENCH_VOID_SHARE * 100), (int) self::TREE_TRENCH_CATCHMENT_M2_PER_M3),
            'Tree crowns are modelled at their recorded diameter (tree register) or, without one, at mature size (radius 28 % of the height); they intercept 2 mm of rain.',
            'Shade is evaluated at one moment (14:00, mid-summer); shade sails let rain through.',
            'Surface temperatures are indicative values of a sunny summer midday, not a thermal simulation.',
            'Green roofs: runoff coefficient 0.5 (extensive) or 0.2 (intensive) per DIN 1986-100, planted inside a 0.5 m gravel strip; their runoff does not drain into rain gardens.',
            sprintf('Ground that is not covered by another object has the surface of its area (OpenStreetMap, or as the user set it), else that of the plot. Ground nobody described counts halfway between %s: half sealed for the runoff, %.0f °C in the sun.', implode(' and ', array_map(static fn (GroundClass $c): string => $c->value, GroundClass::UNKNOWN_BOUNDS)), GroundClass::Unknown->surfaceTemperature()),
        ];
    }
}

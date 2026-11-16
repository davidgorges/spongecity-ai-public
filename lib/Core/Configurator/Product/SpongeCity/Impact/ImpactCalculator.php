<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

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
    private const float CANOPY_RADIUS_SHARE = 0.28;
    private const float CROWN_BASE_SHARE = 0.35;
    /** rain held on leaves and evaporated, millimetres */
    private const float CANOPY_INTERCEPTION_MM = 2.0;
    /** media storage above the ponding depth of a rain garden, metres of water */
    private const float RAIN_GARDEN_MEDIA_STORAGE = 0.12;
    /** a rain garden should not drain more than this many times its own area */
    private const float MAX_CATCHMENT_RATIO = 10.0;
    private const float SHADE_TEMPERATURE_REDUCTION = 12.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;
    private const float SHADE_RAY_STEP_METRES = 1.5;
    private const float SHADE_RAY_REACH_METRES = 60.0;
    private const int MAX_CELLS = 40000;
    private const float MIN_CELL_METRES = 0.5;

    public function calculate(Site $site, int $stormMillimetres): ImpactReport
    {
        $plots = $site->ofType(O::PLOT);
        if ([] === $plots) {
            return new ImpactReport(0.0, $stormMillimetres, [], [], [], ['No plot loaded: load a site first.']);
        }
        $outline = Site::points($plots[0]);
        $plotSurface = GroundClass::fromSurface(is_string($plots[0][O::SURFACE] ?? null) ? $plots[0][O::SURFACE] : O::SURFACE_ASPHALT);

        $baselineSite = new Site(array_values(array_filter($site->objects, static fn (array $o): bool => !in_array($o[O::TYPE], O::INTERVENTIONS, true))));
        $grid = $this->grid($outline);
        $baseline = $this->evaluate($baselineSite, $outline, $plotSurface, $stormMillimetres, $grid);
        $design = $this->evaluate($site, $outline, $plotSurface, $stormMillimetres, $grid);

        $metrics = [
            new Metric(self::METRIC_PERMEABLE_SHARE, 'spongecity.metric.permeable_share', self::UNIT_PERCENT, $baseline['permeable'] * 100, $design['permeable'] * 100,
                'Share of the plot ground (without roofs) whose runoff coefficient is below 0.7 (DWA-A 138 / DIN 1986-100 classes).'),
            new Metric(self::METRIC_RUNOFF, 'spongecity.metric.runoff', self::UNIT_CUBIC_METRES, $baseline['runoff'], $design['runoff'],
                sprintf('Surface runoff in a %d mm/h design storm: sum of area x runoff coefficient x rain depth, minus what rain gardens and canopies hold back.', $stormMillimetres), false),
            new Metric(self::METRIC_RETENTION, 'spongecity.metric.retention', self::UNIT_CUBIC_METRES, $baseline['retained'], $design['retained'],
                'Rain kept on the plot: infiltrated, stored in rain gardens or intercepted by tree crowns.'),
            new Metric(self::METRIC_PEAK_COEFFICIENT, 'spongecity.metric.runoff_coefficient', self::UNIT_NONE, $baseline['psi'], $design['psi'],
                'Mean runoff coefficient of the plot: 1.0 = all rain runs off, 0.0 = all rain stays.', false),
            new Metric(self::METRIC_SHADE, 'spongecity.metric.shade', self::UNIT_PERCENT, $baseline['shade'] * 100, $design['shade'] * 100,
                sprintf('Share of the open ground in shade at 14:00 on a summer day (sun %d° high, from the south-west): shadows of buildings, tree crowns and shade sails.', (int) self::SUN_ELEVATION)),
            new Metric(self::METRIC_SURFACE_TEMPERATURE, 'spongecity.metric.surface_temperature', self::UNIT_CELSIUS, $baseline['temperature'], $design['temperature'],
                sprintf('Mean midday surface temperature of the open ground, indicative literature values per surface, shaded ground %d K cooler.', (int) self::SHADE_TEMPERATURE_REDUCTION), false),
        ];

        return new ImpactReport(
            $baseline['area'],
            $stormMillimetres,
            $metrics,
            $design['classes'],
            $this->assumptions(),
            array_values(array_unique([...$baseline['warnings'], ...$design['warnings']])),
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
        $areaM2 = Polygon::area($outline) / 1_000_000;
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
     * @param list<array{0: float, 1: float}>                       $outline
     * @param array{cell: float, cells: list<array{0: float, 1: float}>} $grid
     *
     * @return array{area: float, permeable: float, runoff: float, retained: float, psi: float, shade: float, temperature: float, classes: array<string, float>, warnings: list<string>}
     */
    private function evaluate(Site $site, array $outline, GroundClass $plotSurface, int $storm, array $grid): array
    {
        $layers = $this->layers($site);
        $occluders = $this->occluders($site);
        $cellM2 = ($grid['cell'] / 1000) ** 2;
        $rain = $storm / self::MILLIMETRES_PER_METRE;

        $classes = [];
        $runoff = 0.0;
        $rainTotal = 0.0;
        $sealedGround = 0.0;
        $groundCells = 0;
        $permeableGround = 0;
        $shadedGround = 0;
        $temperatureSum = 0.0;
        $rainGardenArea = 0.0;
        $rainGardenStorage = 0.0;
        $sealedCatchment = 0.0;
        $warnings = [];

        foreach ($grid['cells'] as [$x, $z]) {
            $class = $this->classAt($layers, $plotSurface, $x, $z);
            $classes[$class->value] = ($classes[$class->value] ?? 0.0) + $cellM2;
            $rainTotal += $cellM2 * $rain;
            $generated = $cellM2 * $class->runoffCoefficient() * $rain;
            $runoff += $generated;
            if ($class->isSealed()) {
                $sealedCatchment += $cellM2;
            }
            if (GroundClass::RainGarden === $class) {
                $rainGardenArea += $cellM2;
                $rainGardenStorage += $cellM2 * ($this->storageAt($layers, $x, $z) / self::MILLIMETRES_PER_METRE + self::RAIN_GARDEN_MEDIA_STORAGE);
            }
            if (GroundClass::Roof === $class) {
                continue;
            }
            ++$groundCells;
            if (!$class->isSealed()) {
                ++$permeableGround;
            } else {
                $sealedGround += $cellM2;
            }
            $shaded = $this->isShaded($occluders, $x, $z);
            $shadedGround += $shaded ? 1 : 0;
            $temperatureSum += $class->surfaceTemperature() - ($shaded ? self::SHADE_TEMPERATURE_REDUCTION : 0.0);
        }

        // rain gardens take the runoff of the sealed area around them, up to their storage and the catchment limit
        $captured = 0.0;
        if ($rainGardenArea > 0.0) {
            $reachable = min($sealedCatchment, $rainGardenArea * self::MAX_CATCHMENT_RATIO);
            $catchmentRunoff = $reachable * GroundClass::Asphalt->runoffCoefficient() * $rain;
            $captured = min($rainGardenStorage, $catchmentRunoff);
            if ($sealedCatchment > $rainGardenArea * self::MAX_CATCHMENT_RATIO) {
                $warnings[] = sprintf('The rain gardens (%.0f m²) are small for the %.0f m² of sealed surface around them (max. 10 times their area drains into one).', $rainGardenArea, $sealedCatchment);
            }
        }

        // tree crowns hold back a little rain over the ground below them
        $interception = 0.0;
        foreach ($occluders['trees'] as $tree) {
            $interception += M_PI * ($tree['radius'] / 1000) ** 2 * self::CANOPY_INTERCEPTION_MM / self::MILLIMETRES_PER_METRE;
        }
        $interception = min($interception, max(0.0, $runoff - $captured));

        $runoffAfter = max(0.0, $runoff - $captured - $interception);
        $total = count($grid['cells']) * $cellM2;

        return [
            'area' => $total,
            'permeable' => $groundCells > 0 ? $permeableGround / $groundCells : 0.0,
            'runoff' => $runoffAfter,
            'retained' => max(0.0, $rainTotal - $runoffAfter),
            'psi' => $rainTotal > 0 ? $runoffAfter / $rainTotal : 0.0,
            'shade' => $groundCells > 0 ? $shadedGround / $groundCells : 0.0,
            'temperature' => $groundCells > 0 ? $temperatureSum / $groundCells : 0.0,
            'classes' => $classes,
            'warnings' => $warnings,
        ];
    }

    /**
     * Ground layers from the top: what lies on the plot's own surface, highest priority first.
     *
     * @return list<array{class: GroundClass, polygon: list<array{0: float, 1: float}>, storage: float}>
     */
    private function layers(Site $site): array
    {
        $layers = [];
        $add = static function (GroundClass $class, array $object, float $storage = 0.0) use (&$layers): void {
            foreach (Site::outlinesOf($object) as $polygon) {
                if (count($polygon) >= 3) {
                    $layers[] = ['class' => $class, 'polygon' => $polygon, 'storage' => $storage];
                }
            }
        };
        // order of the groups = priority, the first match at a point wins
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
                O::RAINGARDEN => $add(GroundClass::RainGarden, $object, is_numeric($object[O::STORAGE] ?? null) ? (float) $object[O::STORAGE] : 0.0),
                O::PLANTING => $add(GroundClass::Planting, $object),
                O::PERMEABLE => $add(GroundClass::fromPermeableKind(is_string($object[O::KIND] ?? null) ? $object[O::KIND] : ''), $object),
                default => null,
            };
        }
        foreach ($site->objects as $object) {
            if (O::PAVING === $object[O::TYPE]) {
                $add(GroundClass::Paving, $object);
            }
        }

        return $layers;
    }

    /**
     * @param list<array{class: GroundClass, polygon: list<array{0: float, 1: float}>, storage: float}> $layers
     */
    private function classAt(array $layers, GroundClass $plotSurface, float $x, float $z): GroundClass
    {
        foreach ($layers as $layer) {
            if (Polygon::contains($layer['polygon'], $x, $z)) {
                return $layer['class'];
            }
        }

        return $plotSurface;
    }

    /**
     * @param list<array{class: GroundClass, polygon: list<array{0: float, 1: float}>, storage: float}> $layers
     */
    private function storageAt(array $layers, float $x, float $z): float
    {
        foreach ($layers as $layer) {
            if (GroundClass::RainGarden === $layer['class'] && Polygon::contains($layer['polygon'], $x, $z)) {
                return $layer['storage'];
            }
        }

        return 0.0;
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
     * A cell is shaded when the ray towards the sun meets a building, a tree crown or a sail.
     *
     * @param array{buildings: list<array{polygon: list<array{0: float, 1: float}>, height: float}>, trees: list<array{x: float, z: float, radius: float, height: float}>, sails: list<array{polygon: list<array{0: float, 1: float}>, height: float}>} $occluders
     */
    private function isShaded(array $occluders, float $x, float $z): bool
    {
        $azimuth = deg2rad(self::SUN_AZIMUTH_FROM_NORTH);
        // x east, z south: towards the sun is the azimuth direction, north = -z
        $dx = sin($azimuth);
        $dz = -cos($azimuth);
        $tan = tan(deg2rad(self::SUN_ELEVATION));

        foreach ($occluders['sails'] as $sail) {
            $d = $sail['height'] / $tan;
            if (Polygon::contains($sail['polygon'], $x + $dx * $d, $z + $dz * $d)) {
                return true;
            }
        }
        for ($d = self::SHADE_RAY_STEP_METRES; $d <= self::SHADE_RAY_REACH_METRES; $d += self::SHADE_RAY_STEP_METRES) {
            $meters = $d * 1000;
            $rayHeight = $meters * $tan;
            $px = $x + $dx * $meters;
            $pz = $z + $dz * $meters;
            foreach ($occluders['trees'] as $tree) {
                if ($rayHeight >= $tree['height'] * self::CROWN_BASE_SHARE && $rayHeight <= $tree['height'] && hypot($px - $tree['x'], $pz - $tree['z']) <= $tree['radius']) {
                    return true;
                }
            }
            foreach ($occluders['buildings'] as $building) {
                if ($rayHeight <= $building['height'] && Polygon::contains($building['polygon'], $px, $pz)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function assumptions(): array
    {
        return [
            'Runoff coefficients per surface follow typical DWA-A 138 / DIN 1986-100 values (asphalt 0.9, roof 0.9, permeable pavers 0.4, gravel 0.6, lawn 0.15).',
            'Rain gardens are connected to the sealed surface around them; they hold ponding depth plus 0.12 m of water in the planting medium.',
            'Tree crowns are modelled at their recorded diameter (tree register) or, without one, at mature size (radius 28 % of the height); they intercept 2 mm of rain.',
            'Shade is evaluated at one moment (14:00, mid-summer); shade sails let rain through.',
            'Surface temperatures are indicative values of a sunny summer midday, not a thermal simulation.',
            'Ground that is not covered by another object has the surface of the plot.',
        ];
    }
}

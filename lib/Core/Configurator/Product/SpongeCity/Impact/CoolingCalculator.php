<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

use Core\Configurator\Product\SpongeCity\Impact\CoolingScenario as K;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteObject as O;

/**
 * Cooling and water of a plot on a hot summer day and over a year, today and with the plan:
 * evapotranspiration and the heat it takes up, surface temperature split into shade and surface,
 * an indicative air temperature, the perceived temperature (PET) where people stand, and the yearly
 * water balance. Each kind of measure is credited with what the plan loses without it.
 *
 * Planning-level estimates from literature coefficients (in GroundClass and below), not an urban
 * climate simulation such as ENVI-met or PALM-4U. The assumptions travel with the report.
 */
final readonly class CoolingCalculator
{
    /** reference hot day: air temperature at 14:00 and FAO-56 reference evapotranspiration, north Germany */
    public const float REFERENCE_AIR_TEMPERATURE = 30.0;
    public const float REFERENCE_EVAPOTRANSPIRATION_MM = 5.0;
    /** yearly precipitation, Hamburg-Fuhlsbüttel 1991–2020 (DWD), rounded */
    public const float DEFAULT_ANNUAL_PRECIPITATION_MM = 790.0;
    /** shaded ground gets less radiation and evaporates less */
    private const float SHADED_EVAPORATION_FACTOR = 0.6;
    /** transpiration of an urban tree per m² of crown, as a share of ET0 (measured street trees: 0.4 to 1.0) */
    private const float TREE_CROP_COEFFICIENT = 0.7;
    /** transpiration of a greened façade per m² of wall, as a share of ET0 (measured climbers: 1 to 3 litres a day per m²) */
    public const float FACADE_CROP_COEFFICIENT = 0.4;
    /** a sunlit wall behind climbers at midday, kelvin cooler than a bare one (measurements: 5 to 15 K) */
    public const float FACADE_WALL_COOLING_K = 10.0;
    /** the key of the façades' evaporation among the ground classes' */
    public const string FACADE_EVAPORATION = 'facade_green';
    /** latent heat of vaporisation, 2.45 MJ per kg of water */
    private const float KWH_PER_LITRE = 2.45 / 3.6;
    /** the hours of a summer day over which the evaporation is spread for its mean power */
    private const float DAYLIGHT_HOURS = 12.0;
    /** near-ground air at block scale, kelvin per percentage point of the plot (literature: 0.1 to 1 K per 10 points of canopy) */
    private const float AIR_COOLING_PER_CANOPY_POINT = 0.04;
    private const float AIR_COOLING_PER_GREEN_POINT = 0.02;
    /** PET classes of VDI 3787 Blatt 2: above 41 °C extreme, above 35 °C strong heat stress */
    public const float PET_EXTREME = 41.0;
    public const float PET_STRONG = 35.0;
    /** share of the yearly rain on a crown held on the leaves and evaporated */
    private const float TREE_INTERCEPTION_SHARE = 0.20;
    /** the runoff a rain garden takes from the sealed ground around it: infiltrated, the rest evaporates */
    private const float RAIN_GARDEN_INFILTRATION_SHARE = 0.80;
    /** water balance of undeveloped ground in the north German lowland, for the comparison DWA-M 102-4 asks for */
    public const array NATURAL_WATER_BALANCE = [GroundClass::RUNOFF => 0.04, GroundClass::INFILTRATION => 0.33, GroundClass::EVAPORATION => 0.63];
    private const float PERCENT = 100.0;
    private const float MILLIMETRES_PER_METRE = 1000.0;

    public function __construct(
        private ImpactCalculator $surveyor,
    ) {
    }

    public function calculate(Site $site, float $annualPrecipitationMm = self::DEFAULT_ANNUAL_PRECIPITATION_MM): ?CoolingReport
    {
        $design = $this->surveyor->survey($site);
        $baseline = $this->surveyor->survey(ImpactCalculator::without($site, O::INTERVENTIONS));
        if (null === $design || null === $baseline) {
            return null;
        }

        $without = [];
        foreach (O::INTERVENTIONS as $type) {
            $count = count($site->ofType($type));
            if ($count > 0) {
                $survey = $this->surveyor->survey(ImpactCalculator::without($site, [$type]));
                \assert(null !== $survey);
                $without[$type] = ['count' => $count, 'scenario' => $this->scenario($survey, $annualPrecipitationMm)];
            }
        }

        $warnings = [];
        if ([] !== $site->ofType(O::NEWTREE)) {
            $warnings[] = 'New trees are counted at mature crown size: in their first 10 to 15 years they shade and transpire only a fraction of it.';
        }
        if ($design->groundArea() < 10_000.0) {
            $warnings[] = sprintf('The plot has %.0f m² of open ground: its air mixes with the surroundings, so the air temperature change is an upper bound; the perceived temperature in the shade is what people notice.', $design->groundArea());
        }

        return new CoolingReport(
            $design->plotSquareMetres,
            $design->groundArea(),
            [
                'air_temperature_c' => self::REFERENCE_AIR_TEMPERATURE,
                'reference_evapotranspiration_mm' => self::REFERENCE_EVAPOTRANSPIRATION_MM,
                'description' => 'A sunny, hot summer day (30 °C at 14:00), water available in the ground; shade at 14:00 as in calculate_impact.',
            ],
            $annualPrecipitationMm,
            $this->scenario($baseline, $annualPrecipitationMm),
            $this->scenario($design, $annualPrecipitationMm),
            $without,
            self::assumptions(),
            $warnings,
        );
    }

    private function scenario(GroundSurvey $survey, float $precipitationMm): K
    {
        $plot = max($survey->plotSquareMetres, 1.0);
        $ground = max($survey->groundArea(), 1.0);
        $et0 = self::REFERENCE_EVAPOTRANSPIRATION_MM;

        // a hot day: what the ground evaporates (less in the shade), what the trees transpire, how people feel
        $evaporatedBy = [];
        $material = 0.0;
        $shadeCooling = 0.0;
        $evaporatingArea = 0.0;
        $treeShade = 0.0;
        $pet = 0.0;
        $extreme = 0.0;
        $strong = 0.0;
        $survey->eachGroundPatch(static function (GroundClass $class, ShadeKind $shade, float $m2) use ($et0, &$evaporatedBy, &$material, &$shadeCooling, &$evaporatingArea, &$treeShade, &$pet, &$extreme, &$strong): void {
            // mm over m² are litres
            $litres = $m2 * $et0 * $class->cropCoefficient() * ($shade->isShaded() ? self::SHADED_EVAPORATION_FACTOR : 1.0);
            if ($litres > 0.0) {
                $evaporatedBy[$class->value] = ($evaporatedBy[$class->value] ?? 0.0) + $litres;
            }
            $material += $m2 * $class->surfaceTemperature();
            $shadeCooling += $shade->isShaded() ? $m2 * ImpactCalculator::SHADE_TEMPERATURE_REDUCTION : 0.0;
            $evaporatingArea += $class->evaporates() ? $m2 : 0.0;
            $treeShade += ShadeKind::Tree === $shade ? $m2 : 0.0;
            $felt = self::REFERENCE_AIR_TEMPERATURE + self::perceivedOffset($shade, $class->evaporates());
            $pet += $m2 * $felt;
            $extreme += $felt > self::PET_EXTREME ? $m2 : 0.0;
            $strong += $felt > self::PET_STRONG && $felt <= self::PET_EXTREME ? $m2 : 0.0;
        });
        // roofs: in the sun, a green roof evaporates (less than ground, its substrate is thin)
        $roofHeat = 0.0;
        $greenRoof = 0.0;
        $survey->eachRoof(static function (GroundClass $class, float $m2) use ($et0, &$evaporatedBy, &$roofHeat, &$greenRoof): void {
            $litres = $m2 * $et0 * $class->cropCoefficient();
            if ($litres > 0.0) {
                $evaporatedBy[$class->value] = ($evaporatedBy[$class->value] ?? 0.0) + $litres;
            }
            $roofHeat += $m2 * $class->surfaceTemperature();
            $greenRoof += GroundClass::Roof === $class ? 0.0 : $m2;
        });
        // greened walls: not ground, but they transpire
        if ($survey->facadeGreenSquareMetres > 0.0) {
            $evaporatedBy[self::FACADE_EVAPORATION] = $survey->facadeGreenSquareMetres * $et0 * self::FACADE_CROP_COEFFICIENT;
        }
        $roofs = $survey->roofArea();
        $crowns = $survey->crownAreaOnPlot();
        $trees = $crowns * $et0 * self::TREE_CROP_COEFFICIENT;
        $evapotranspiration = array_sum($evaporatedBy) + $trees;
        $latent = $evapotranspiration * self::KWH_PER_LITRE;
        $canopyPoints = min($crowns, $plot) / $plot * self::PERCENT;
        $greenPoints = $evaporatingArea / $plot * self::PERCENT;

        $water = $this->yearlyWater($survey, $precipitationMm);
        $rain = max($survey->plotSquareMetres * $precipitationMm / self::MILLIMETRES_PER_METRE, 1e-9);

        return new K([
            K::KEY_EVAPOTRANSPIRATION => $evapotranspiration,
            K::KEY_EVAPOTRANSPIRATION_TREES => $trees,
            K::KEY_LATENT_COOLING => $latent,
            K::KEY_LATENT_COOLING_POWER => $latent * self::MILLIMETRES_PER_METRE / self::DAYLIGHT_HOURS / $plot,
            K::KEY_SURFACE_TEMPERATURE => ($material - $shadeCooling) / $ground,
            K::KEY_SURFACE_MATERIAL => $material / $ground,
            K::KEY_SHADE_COOLING => $shadeCooling / $ground,
            K::KEY_ROOF_TEMPERATURE => $roofs > 0.0 ? $roofHeat / $roofs : 0.0,
            K::KEY_SITE_SURFACE_TEMPERATURE => ($material - $shadeCooling + $roofHeat) / max($survey->groundArea() + $roofs, 1.0),
            K::KEY_GREEN_ROOF => $greenRoof,
            K::KEY_SHADE => $survey->shadedGroundArea() / $ground * self::PERCENT,
            K::KEY_TREE_SHADE => $treeShade / $ground * self::PERCENT,
            K::KEY_CANOPY => $canopyPoints,
            K::KEY_EVAPORATING => $greenPoints,
            K::KEY_AIR_COOLING => $canopyPoints * self::AIR_COOLING_PER_CANOPY_POINT + $greenPoints * self::AIR_COOLING_PER_GREEN_POINT,
            K::KEY_PET => $pet / $ground,
            K::KEY_EXTREME_HEAT => $extreme / $ground * self::PERCENT,
            K::KEY_STRONG_HEAT => $strong / $ground * self::PERCENT,
            K::KEY_RUNOFF => $water[GroundClass::RUNOFF],
            K::KEY_INFILTRATION => $water[GroundClass::INFILTRATION],
            K::KEY_EVAPORATION => $water[GroundClass::EVAPORATION],
            K::KEY_RUNOFF_SHARE => $water[GroundClass::RUNOFF] / $rain * self::PERCENT,
            K::KEY_INFILTRATION_SHARE => $water[GroundClass::INFILTRATION] / $rain * self::PERCENT,
            K::KEY_EVAPORATION_SHARE => $water[GroundClass::EVAPORATION] / $rain * self::PERCENT,
        ], $evaporatedBy);
    }

    /**
     * Kelvin the perceived temperature (PET) lies above the air at the reference moment: the sun's
     * radiation and the heat the ground gives back, less on green ground, least under leaves.
     */
    private static function perceivedOffset(ShadeKind $shade, bool $greenGround): float
    {
        return match ($shade) {
            ShadeKind::Sun => $greenGround ? 10.0 : 13.0,
            ShadeKind::Building, ShadeKind::Sail => $greenGround ? 2.0 : 4.0,
            ShadeKind::Tree => $greenGround ? 0.0 : 2.0,
        };
    }

    /**
     * A year's rain on the plot, m³: split per ground class, then the rain gardens take the runoff of the
     * sealed ground around them (as in calculate_impact, up to ten times their area) and the crowns
     * evaporate what they hold.
     *
     * @return array{runoff: float, infiltration: float, evaporation: float}
     */
    private function yearlyWater(GroundSurvey $survey, float $precipitationMm): array
    {
        $depth = $precipitationMm / self::MILLIMETRES_PER_METRE;
        $water = [GroundClass::RUNOFF => 0.0, GroundClass::INFILTRATION => 0.0, GroundClass::EVAPORATION => 0.0];
        foreach (GroundClass::cases() as $class) {
            $volume = $survey->area($class) * $depth;
            foreach ($class->yearlyWaterBalance() as $part => $share) {
                $water[$part] += $volume * $share;
            }
        }

        $rainGarden = $survey->area(GroundClass::RainGarden);
        if ($rainGarden > 0.0) {
            $catchment = min($survey->sealedArea(), $survey->rainGardenReach());
            $routed = min($water[GroundClass::RUNOFF], $catchment * $depth * GroundClass::Asphalt->yearlyWaterBalance()[GroundClass::RUNOFF]);
            $water[GroundClass::RUNOFF] -= $routed;
            $water[GroundClass::INFILTRATION] += $routed * self::RAIN_GARDEN_INFILTRATION_SHARE;
            $water[GroundClass::EVAPORATION] += $routed * (1.0 - self::RAIN_GARDEN_INFILTRATION_SHARE);
        }

        // held on the leaves: taken from what would have run off or seeped in below, in their proportion
        $leaving = $water[GroundClass::RUNOFF] + $water[GroundClass::INFILTRATION];
        $intercepted = min($leaving, $survey->crownAreaOnPlot() * $depth * self::TREE_INTERCEPTION_SHARE);
        if ($intercepted > 0.0) {
            $water[GroundClass::RUNOFF] -= $intercepted * $water[GroundClass::RUNOFF] / $leaving;
            $water[GroundClass::INFILTRATION] -= $intercepted * $water[GroundClass::INFILTRATION] / $leaving;
            $water[GroundClass::EVAPORATION] += $intercepted;
        }

        return $water;
    }

    /**
     * @return list<string>
     */
    private static function assumptions(): array
    {
        return [
            sprintf('Hot day: %d °C air at 14:00, reference evapotranspiration ET0 %.1f mm/day (FAO-56, north Germany), water available in the ground. In a drought, lawn and planting evaporate far less.', (int) self::REFERENCE_AIR_TEMPERATURE, self::REFERENCE_EVAPOTRANSPIRATION_MM),
            sprintf('Evaporation per ground = ET0 x crop coefficient (lawn 0.8, planting and rain garden 1.0, water 1.05, grass pavers 0.5, permeable pavers 0.15, asphalt 0), %d %% of it in the shade.', (int) (self::SHADED_EVAPORATION_FACTOR * self::PERCENT)),
            sprintf('Trees transpire %.1f x ET0 per m² of crown (trees with their trunk on the plot; measured urban trees: 0.4 to 1.0).', self::TREE_CROP_COEFFICIENT),
            sprintf('Greened façades transpire %.1f x ET0 per m² of wall (climbers: 1 to 3 litres a day per m²) and keep the wall behind them about %d K cooler at midday; they do not change the open ground.', self::FACADE_CROP_COEFFICIENT, (int) self::FACADE_WALL_COOLING_K),
            'Evaporative cooling: 0.68 kWh of heat taken up per litre evaporated (latent heat 2.45 MJ/kg); the power is spread over 12 daylight hours and the plot area.',
            'The surface temperatures per ground already contain their own evaporation (lawn 31 °C, asphalt 62 °C): do not add the evaporative cooling to them. Shaded ground is 12 K cooler, as in calculate_impact.',
            'Roofs: 55 °C (gravel, bitumen), extensive green roof 36 °C, intensive 31 °C, evaporating 0.5 and 0.8 x ET0; roofs are not shaded in the model. surface_temperature_c is the open ground only, site_surface_temperature_c the whole plot with its roofs. A green roof cools the roof and the rooms below it; its effect on the air at street level is small and not counted in air_cooling_k.',
            sprintf('Air temperature: indicative block-scale relation, %.2f K per percentage point of canopy cover and %.2f K per point of green or wet ground (literature: 0.1 to 1 K per 10 points of canopy). Directly under a crown the air is 1 to 3 K cooler.', self::AIR_COOLING_PER_CANOPY_POINT, self::AIR_COOLING_PER_GREEN_POINT),
            sprintf('Perceived temperature (PET) above the %d °C air: full sun +13 K on hard and +10 K on green ground, building or sail shade +4 / +2 K, tree shade +2 / 0 K (literature: tree shade lowers PET by 10 to 15 K). Heat stress classes per VDI 3787 Blatt 2: above %d °C strong, above %d °C extreme.', (int) self::REFERENCE_AIR_TEMPERATURE, (int) self::PET_STRONG, (int) self::PET_EXTREME),
            sprintf('Yearly water balance: %.0f mm of rain (Hamburg-Fuhlsbüttel 1991–2020 unless given), split per ground with typical DWA-M 102-4 shares (asphalt 80 %% runoff / 20 %% evaporation, lawn 3 / 40 / 57 %%); rain gardens infiltrate 80 %% of the runoff of up to ten times their area of sealed ground; crowns evaporate 20 %% of the rain on them.', self::DEFAULT_ANNUAL_PRECIPITATION_MM),
            sprintf('Undeveloped reference (north German lowland, indicative): %d %% runoff, %d %% infiltration, %d %% evaporation. DWA-M 102-4 takes the site\'s own reference from the Hydrologischer Atlas.', (int) (self::NATURAL_WATER_BALANCE[GroundClass::RUNOFF] * self::PERCENT), (int) (self::NATURAL_WATER_BALANCE[GroundClass::INFILTRATION] * self::PERCENT), (int) (self::NATURAL_WATER_BALANCE[GroundClass::EVAPORATION] * self::PERCENT)),
            'A measure\'s contribution is the plan with it minus the plan without it: contributions overlap (a tree over a rain garden) and do not add up exactly to the total change.',
            'Planning-level estimates from literature values, not an urban climate simulation (ENVI-met, PALM-4U).',
        ];
    }
}

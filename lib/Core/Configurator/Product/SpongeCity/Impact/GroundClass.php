<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

use Core\Configurator\Site\SiteObject as O;

/**
 * What a patch of ground is, with the two numbers the balance needs.
 *
 * Runoff coefficients (psi) follow the typical values of DWA-A 138 / DIN 1986-100
 * for a design storm: the share of rain that leaves the patch as surface runoff.
 * Surface temperatures are indicative midday values of a sunny summer day from
 * urban heat island literature, in degrees Celsius.
 * Crop coefficients scale the reference evapotranspiration of a hot day (FAO-56),
 * with water available in the ground; the yearly water balance splits the rain
 * into runoff, infiltration and evaporation like the typical values of DWA-M 102-4.
 */
enum GroundClass: string
{
    /** the parts of yearlyWaterBalance() */
    public const string RUNOFF = 'runoff';
    public const string INFILTRATION = 'infiltration';
    public const string EVAPORATION = 'evaporation';

    case Roof = 'roof';
    /** green roofs (FLL): sedum on 8 to 12 cm substrate, or a roof garden on 25 cm and more */
    case GreenRoofExtensive = 'greenroof_extensive';
    case GreenRoofIntensive = 'greenroof_intensive';
    case Asphalt = 'asphalt';
    case Concrete = 'concrete';
    case Paving = 'paving';
    case PermeablePavers = 'permeable_pavers';
    case PermeableGravel = 'permeable_gravel';
    case GrassPavers = 'grass_pavers';
    case Gravel = 'gravel';
    case Lawn = 'lawn';
    case Soil = 'soil';
    case Planting = 'planting';
    case RainGarden = 'raingarden';
    case Water = 'water';

    public function runoffCoefficient(): float
    {
        return match ($this) {
            self::Roof => 0.90,
            // DIN 1986-100: extensive green roof under 10 cm 0.5, intensive from 30 cm 0.2
            self::GreenRoofExtensive => 0.50,
            self::GreenRoofIntensive => 0.20,
            self::Asphalt, self::Concrete => 0.90,
            self::Paving => 0.75,
            self::PermeablePavers => 0.40,
            self::PermeableGravel => 0.30,
            self::GrassPavers => 0.25,
            self::Gravel => 0.60,
            self::Lawn => 0.15,
            self::Soil => 0.20,
            self::Planting => 0.10,
            self::RainGarden => 0.0,
            self::Water => 0.0,
        };
    }

    public function surfaceTemperature(): float
    {
        return match ($this) {
            self::Roof => 55.0,
            self::GreenRoofExtensive => 36.0,
            self::GreenRoofIntensive => 31.0,
            self::Asphalt => 62.0,
            self::Concrete => 52.0,
            self::Paving => 48.0,
            self::PermeablePavers => 44.0,
            self::PermeableGravel => 46.0,
            self::GrassPavers => 36.0,
            self::Gravel => 47.0,
            self::Lawn => 31.0,
            self::Soil => 38.0,
            self::Planting => 29.0,
            self::RainGarden => 28.0,
            self::Water => 24.0,
        };
    }

    /**
     * Evaporation (and transpiration of what grows there) as a share of the reference
     * evapotranspiration ET0 of a hot summer day, FAO-56 style, with water in the ground.
     */
    public function cropCoefficient(): float
    {
        return match ($this) {
            self::Roof, self::Asphalt, self::Concrete => 0.0,
            // sedum closes its pores in the heat and the thin substrate dries out
            self::GreenRoofExtensive => 0.50,
            self::GreenRoofIntensive => 0.80,
            self::Paving => 0.05,
            self::PermeablePavers => 0.15,
            self::PermeableGravel, self::Gravel => 0.10,
            self::GrassPavers => 0.50,
            self::Soil => 0.40,
            self::Lawn => 0.80,
            self::Planting, self::RainGarden => 1.0,
            self::Water => 1.05,
        };
    }

    /** green or wet ground that cools by evaporating, not only hard ground with joints */
    public function evaporates(): bool
    {
        return $this->cropCoefficient() >= 0.5;
    }

    /**
     * Where a year's rain on this ground goes, as shares that add up to 1: typical values of the
     * water balance per DWA-M 102-4 for a north German climate (rain gardens: their own rain only).
     *
     * @return array{runoff: float, infiltration: float, evaporation: float}
     */
    public function yearlyWaterBalance(): array
    {
        [$runoff, $infiltration, $evaporation] = match ($this) {
            self::Roof, self::Asphalt, self::Concrete => [0.80, 0.0, 0.20],
            // FLL / DWA-M 102-4: a green roof keeps 50 to 60 % (extensive) or 70 to 90 % (intensive) of a year's rain
            self::GreenRoofExtensive => [0.45, 0.0, 0.55],
            self::GreenRoofIntensive => [0.20, 0.0, 0.80],
            self::Paving => [0.55, 0.20, 0.25],
            self::PermeablePavers => [0.25, 0.45, 0.30],
            self::PermeableGravel => [0.10, 0.60, 0.30],
            self::GrassPavers => [0.10, 0.50, 0.40],
            self::Gravel => [0.30, 0.40, 0.30],
            self::Lawn => [0.03, 0.40, 0.57],
            self::Soil => [0.05, 0.50, 0.45],
            self::Planting => [0.02, 0.35, 0.63],
            self::RainGarden => [0.0, 0.80, 0.20],
            self::Water => [0.0, 0.0, 1.0],
        };

        return [self::RUNOFF => $runoff, self::INFILTRATION => $infiltration, self::EVAPORATION => $evaporation];
    }

    /** on a roof, not open ground: what people walk on is measured without it */
    public function isRoof(): bool
    {
        return in_array($this, [self::Roof, self::GreenRoofExtensive, self::GreenRoofIntensive], true);
    }

    public static function greenRoof(string $kind): self
    {
        return O::KIND_INTENSIVE === $kind ? self::GreenRoofIntensive : self::GreenRoofExtensive;
    }

    /** a sealed surface sheds most of the rain */
    public function isSealed(): bool
    {
        return $this->runoffCoefficient() >= 0.7;
    }

    public static function fromSurface(string $surface): self
    {
        return match ($surface) {
            O::SURFACE_CONCRETE => self::Concrete,
            O::SURFACE_GRAVEL => self::Gravel,
            O::SURFACE_LAWN => self::Lawn,
            O::SURFACE_SOIL => self::Soil,
            default => self::Asphalt,
        };
    }

    public static function fromPermeableKind(string $kind): self
    {
        return match ($kind) {
            O::KIND_GRAVEL => self::PermeableGravel,
            O::KIND_GRASS_PAVERS => self::GrassPavers,
            default => self::PermeablePavers,
        };
    }
}

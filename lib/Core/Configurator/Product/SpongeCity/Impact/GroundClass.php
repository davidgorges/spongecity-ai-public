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
 */
enum GroundClass: string
{
    case Roof = 'roof';
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

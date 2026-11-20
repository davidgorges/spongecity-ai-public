<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

/**
 * The plot rasterised once: how much of every ground class lies in the sun or in which shade,
 * what the rain gardens hold, and the tree crowns. The impact balance and the cooling report
 * both read their numbers from it, so they describe the same ground.
 */
final readonly class GroundSurvey
{
    /**
     * @param array<string, array<string, float>>   $areas                        m² per GroundClass value and ShadeKind value (roofs, green or not, always in the sun)
     * @param list<array{m2: float, on_plot: bool}> $crowns                       projected crown area of every tree of the site
     * @param float                                 $rainGardenPondingCubicMetres water the rain gardens hold above their planting medium and in their trenches
     * @param float|null                            $rainGardenReachSquareMetres  the most sealed ground the rain gardens can take the runoff of (their area times their catchment ratio); null: MAX_CATCHMENT_RATIO times their area
     */
    public function __construct(
        public float $plotSquareMetres,
        public array $areas,
        public array $crowns,
        public float $rainGardenPondingCubicMetres,
        public ?float $rainGardenReachSquareMetres = null,
    ) {
    }

    /** m² of sealed ground whose runoff the rain gardens can take at most */
    public function rainGardenReach(): float
    {
        return $this->rainGardenReachSquareMetres ?? $this->area(GroundClass::RainGarden) * ImpactCalculator::MAX_CATCHMENT_RATIO;
    }

    /** m², of one class and/or one kind of shade; null means all of them */
    public function area(?GroundClass $class = null, ?ShadeKind $shade = null): float
    {
        $sum = 0.0;
        foreach ($this->areas as $classValue => $byShade) {
            if (null !== $class && $class->value !== $classValue) {
                continue;
            }
            foreach ($byShade as $shadeValue => $m2) {
                if (null === $shade || $shade->value === $shadeValue) {
                    $sum += $m2;
                }
            }
        }

        return $sum;
    }

    /** m² of open ground: everything but roofs, green or not */
    public function groundArea(): float
    {
        return $this->area() - $this->roofArea();
    }

    /** m² of roofs, green roofs included */
    public function roofArea(): float
    {
        $sum = 0.0;
        foreach (GroundClass::cases() as $class) {
            $sum += $class->isRoof() ? $this->area($class) : 0.0;
        }

        return $sum;
    }

    /** m² of open ground in any shade */
    public function shadedGroundArea(): float
    {
        // roofs are recorded in the sun: their shade is not looked at
        return $this->groundArea() - ($this->area(shade: ShadeKind::Sun) - $this->roofArea());
    }

    /**
     * Calls $each for every patch of open ground: its class, its shade and its area.
     *
     * @param callable(GroundClass, ShadeKind, float): void $each
     */
    public function eachGroundPatch(callable $each): void
    {
        foreach ($this->areas as $classValue => $byShade) {
            $class = GroundClass::from($classValue);
            if ($class->isRoof()) {
                continue;
            }
            foreach ($byShade as $shadeValue => $m2) {
                $each($class, ShadeKind::from($shadeValue), $m2);
            }
        }
    }

    /**
     * Calls $each for every kind of roof on the plot: its class and its area.
     *
     * @param callable(GroundClass, float): void $each
     */
    public function eachRoof(callable $each): void
    {
        foreach (GroundClass::cases() as $class) {
            $area = $this->area($class);
            if ($class->isRoof() && $area > 0.0) {
                $each($class, $area);
            }
        }
    }

    /** m² of the plot ground classes whose runoff coefficient is 0.7 or more, roofs included */
    public function sealedArea(): float
    {
        $sum = 0.0;
        foreach (GroundClass::cases() as $class) {
            if ($class->isSealed()) {
                $sum += $this->area($class);
            }
        }

        return $sum;
    }

    /** m² of tree crowns whose trunk stands on the plot */
    public function crownAreaOnPlot(): float
    {
        return array_sum(array_map(static fn (array $crown): float => $crown['on_plot'] ? $crown['m2'] : 0.0, $this->crowns));
    }
}

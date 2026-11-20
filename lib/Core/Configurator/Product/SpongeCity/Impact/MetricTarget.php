<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Impact;

/**
 * What counts as good and as poor for a metric: at or beyond `good` the target is met, at or
 * beyond `poor` the ground works against it, in between it is fair. The direction comes from the
 * metric (higher or lower is better).
 */
final readonly class MetricTarget
{
    public function __construct(
        public float $good,
        public float $poor,
        public string $source,
    ) {
    }

    public function rate(float $value, bool $higherIsBetter): Rating
    {
        if ($higherIsBetter ? $value >= $this->good : $value <= $this->good) {
            return Rating::Good;
        }
        if ($higherIsBetter ? $value <= $this->poor : $value >= $this->poor) {
            return Rating::Poor;
        }

        return Rating::Fair;
    }
}

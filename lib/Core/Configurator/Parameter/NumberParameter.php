<?php

declare(strict_types=1);

namespace Core\Configurator\Parameter;

use Webmozart\Assert\Assert;

/**
 * An integer dimension (millimetres, pieces, …) within min..max, snapped to step.
 */
final readonly class NumberParameter implements Parameter
{
    public function __construct(
        private string $name,
        private string $label,
        public int $min,
        public int $max,
        public int $step = 1,
        private ?int $default = null,
        public string $unit = Unit::MILLIMETRE,
        private string $group = ParameterGroup::DIMENSIONS,
    ) {
        Assert::greaterThanEq($max, $min);
        Assert::greaterThan($step, 0);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function group(): string
    {
        return $this->group;
    }

    public function defaultValue(): int
    {
        return $this->clamp($this->default ?? $this->min);
    }

    public function resolve(mixed $raw): int
    {
        if (!is_numeric($raw)) {
            return $this->defaultValue();
        }

        return $this->clamp((int) round((float) $raw));
    }

    private function clamp(int $value): int
    {
        $snapped = $this->min + (int) round(($value - $this->min) / $this->step) * $this->step;

        return max($this->min, min($this->max, $snapped));
    }
}

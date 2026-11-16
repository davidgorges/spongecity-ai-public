<?php

declare(strict_types=1);

namespace Core\Configurator\Parameter;

/**
 * An optional extra that is either on or off (side wall, lighting, …).
 */
final readonly class BooleanParameter implements Parameter
{
    private const array TRUTHY = [true, 1, '1', 'true', 'on', 'yes'];

    public function __construct(
        private string $name,
        private string $label,
        private bool $default = false,
        private string $group = ParameterGroup::EXTRAS,
    ) {
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

    public function defaultValue(): bool
    {
        return $this->default;
    }

    public function resolve(mixed $raw): bool
    {
        if (null === $raw) {
            return $this->default;
        }

        return in_array($raw, self::TRUTHY, true);
    }
}

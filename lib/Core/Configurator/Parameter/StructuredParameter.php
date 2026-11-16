<?php

declare(strict_types=1);

namespace Core\Configurator\Parameter;

/**
 * A parameter whose value has an inner structure (a list of runs, gates, …),
 * kept as one canonical string. The rules live in its StructuredValue; the
 * form renders the value's own editor.
 *
 * Option prices leave it out: there are no alternatives to list, the total
 * shows the effect of every change.
 */
final readonly class StructuredParameter implements Parameter
{
    public function __construct(
        private string $name,
        private string $label,
        public StructuredValue $type,
        private string $group = ParameterGroup::DIMENSIONS,
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

    public function editor(): string
    {
        return $this->type->editor();
    }

    public function defaultValue(): string
    {
        return $this->type->defaultValue();
    }

    public function resolve(mixed $raw): string
    {
        return null === $raw ? $this->type->defaultValue() : $this->type->normalize($raw);
    }

    public function isSameInput(mixed $raw, string $resolved): bool
    {
        return $this->type->isSameInput($raw, $resolved);
    }
}

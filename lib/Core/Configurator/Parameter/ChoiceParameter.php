<?php

declare(strict_types=1);

namespace Core\Configurator\Parameter;

use Webmozart\Assert\Assert;

/**
 * One of a list of options (roofing, colour, mounting, …).
 */
final readonly class ChoiceParameter implements Parameter
{
    /**
     * @param list<Choice> $choices
     */
    public function __construct(
        private string $name,
        private string $label,
        public array $choices,
        private ?string $default = null,
        private string $group = ParameterGroup::OPTIONS,
    ) {
        Assert::notEmpty($choices);
        Assert::allIsInstanceOf($choices, Choice::class);
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

    public function defaultValue(): string
    {
        if (null !== $this->default && $this->has($this->default)) {
            return $this->default;
        }

        return $this->choices[0]->value;
    }

    public function resolve(mixed $raw): string
    {
        return is_string($raw) && $this->has($raw) ? $raw : $this->defaultValue();
    }

    public function choiceLabel(string $value): string
    {
        foreach ($this->choices as $choice) {
            if ($choice->value === $value) {
                return $choice->label;
            }
        }

        return $value;
    }

    private function has(string $value): bool
    {
        foreach ($this->choices as $choice) {
            if ($choice->value === $value) {
                return true;
            }
        }

        return false;
    }
}

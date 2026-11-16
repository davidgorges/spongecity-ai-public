<?php

declare(strict_types=1);

namespace Core\Configurator\Engine;

use Webmozart\Assert\Assert;

/**
 * Resolved values of a product's parameters: every value is within its
 * parameter's bounds. While the ConfigurationResolver works, it holds only the
 * parameters resolved so far, so definitions read values with a fallback.
 */
final readonly class Configuration
{
    /**
     * @param array<string, int|string|bool> $values
     */
    public function __construct(private array $values = [])
    {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function int(string $name, ?int $fallback = null): int
    {
        $value = $this->values[$name] ?? $fallback;
        Assert::integer($value, sprintf('Parameter "%s" is not a resolved number.', $name));

        return $value;
    }

    public function string(string $name, ?string $fallback = null): string
    {
        $value = $this->values[$name] ?? $fallback;
        Assert::string($value, sprintf('Parameter "%s" is not a resolved choice.', $name));

        return $value;
    }

    public function bool(string $name, ?bool $fallback = null): bool
    {
        $value = $this->values[$name] ?? $fallback;
        Assert::boolean($value, sprintf('Parameter "%s" is not a resolved switch.', $name));

        return $value;
    }

    public function with(string $name, int|string|bool $value): self
    {
        return new self([...$this->values, $name => $value]);
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function toArray(): array
    {
        return $this->values;
    }
}

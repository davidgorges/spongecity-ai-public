<?php

declare(strict_types=1);

namespace Core\Configurator\Engine;

use Core\Configurator\Definition\MigratesInput;
use Core\Configurator\Definition\ProductDefinition;
use Core\Configurator\Parameter\Parameter;
use Core\Configurator\Parameter\StructuredParameter;

/**
 * Turns raw input (query string, live props, a stored request) into a
 * Configuration, parameter by parameter in definition order, so a parameter's
 * bounds can depend on the values before it. Values that had to be changed are
 * reported as adjustments for the UI to explain. Input in an older shape is
 * first migrated by definitions that implement MigratesInput.
 */
final class ConfigurationResolver
{
    /**
     * @param array<string, mixed> $raw
     */
    public function resolve(ProductDefinition $definition, array $raw): ResolvedConfiguration
    {
        if ($definition instanceof MigratesInput) {
            $raw = $definition->migrateInput($raw);
        }
        $configuration = new Configuration();
        $adjusted = [];

        foreach ($definition->parameters($configuration)->names() as $name) {
            $parameter = $definition->parameters($configuration)->get($name);
            $value = $parameter->resolve($raw[$name] ?? null);
            $configuration = $configuration->with($name, $value);

            if (array_key_exists($name, $raw) && !$this->sameValue($parameter, $raw[$name], $value)) {
                $adjusted[] = $name;
            }
        }

        return new ResolvedConfiguration(
            $configuration,
            $definition->parameters($configuration),
            $adjusted,
        );
    }

    private function sameValue(Parameter $parameter, mixed $raw, int|string|bool $resolved): bool
    {
        return match (true) {
            $parameter instanceof StructuredParameter => is_string($resolved) && $parameter->isSameInput($raw, $resolved),
            is_int($resolved) => is_numeric($raw) && (int) round((float) $raw) === $resolved,
            is_bool($resolved) => true,
            default => $raw === $resolved,
        };
    }
}

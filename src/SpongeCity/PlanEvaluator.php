<?php

declare(strict_types=1);

namespace App\SpongeCity;

use Core\Configurator\Definition\ProductDefinitionRegistry;
use Core\Configurator\Engine\ConfiguratorEngine;
use Core\Configurator\Engine\Evaluation;
use Core\Configurator\Product\SpongeCity\SpongeCityDefinition;

/**
 * Evaluates the stored configuration of a plan with the sponge city definition.
 */
final readonly class PlanEvaluator
{
    public function __construct(
        private ProductDefinitionRegistry $definitions,
        private ConfiguratorEngine $engine,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public function evaluate(array $values): Evaluation
    {
        return $this->engine->evaluate($this->definitions->get(SpongeCityDefinition::KEY), $values);
    }
}

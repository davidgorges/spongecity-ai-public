<?php

declare(strict_types=1);

namespace Core\Configurator\Pricing;

use Core\Configurator\Engine\Evaluation;
use Core\Configurator\Model\ConfiguratorProduct;
use Core\Configurator\Parameter\BooleanParameter;
use Core\Configurator\Parameter\ChoiceParameter;
use Core\Configurator\ProductEvaluator;

/**
 * Prices the alternatives of every choice and switch by evaluating the
 * configuration with that one value changed. Dimensions are left out: a slider
 * shows its effect on the total directly.
 *
 * The other values go through the resolver as usual, so an option that
 * narrows a bound (e.g. a shorter maximum depth for glass) is priced with the
 * corrected dimension the customer would get.
 */
final readonly class OptionPricing
{
    public function __construct(private ProductEvaluator $evaluator)
    {
    }

    public function forEvaluation(ConfiguratorProduct $product, Evaluation $current): OptionPrices
    {
        $choiceDeltas = [];
        $toggleCosts = [];
        foreach ($current->resolved->parameters as $name => $parameter) {
            if ($parameter instanceof ChoiceParameter) {
                $choiceDeltas[$name] = $this->choiceDeltas($product, $current, $parameter);
            } elseif ($parameter instanceof BooleanParameter) {
                $toggleCosts[$name] = $this->toggleCost($product, $current, $parameter);
            }
        }

        return new OptionPrices($choiceDeltas, $toggleCosts);
    }

    /**
     * @return array<string, int> choice value → difference to the current total; the selected value is left out
     */
    private function choiceDeltas(ConfiguratorProduct $product, Evaluation $current, ChoiceParameter $parameter): array
    {
        $name = $parameter->name();
        $selected = $current->configuration()->string($name);
        $deltas = [];
        foreach ($parameter->choices as $choice) {
            if ($choice->value !== $selected) {
                $deltas[$choice->value] = $this->totalWith($product, $current, $name, $choice->value) - $current->quote->total();
            }
        }

        return $deltas;
    }

    private function toggleCost(ConfiguratorProduct $product, Evaluation $current, BooleanParameter $parameter): int
    {
        $name = $parameter->name();
        $isOn = $current->configuration()->bool($name);
        $other = $this->totalWith($product, $current, $name, !$isOn);

        return $isOn ? $current->quote->total() - $other : $other - $current->quote->total();
    }

    private function totalWith(ConfiguratorProduct $product, Evaluation $current, string $name, string|bool $value): int
    {
        return $this->evaluator->evaluate($product, $current->configuration()->with($name, $value)->toArray())->quote->total();
    }
}

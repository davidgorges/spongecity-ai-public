<?php

declare(strict_types=1);

namespace Core\Configurator\Pricing;

/**
 * What each alternative would do to the total of the current configuration,
 * in net cents, so the configurator can show "+ 240 €" next to an option.
 */
final readonly class OptionPrices
{
    /**
     * @param array<string, array<string, int>> $choiceDeltas parameter → choice value → difference to the current total
     * @param array<string, int>                $toggleCosts  boolean parameter → total with it on minus total with it off
     */
    public function __construct(
        private array $choiceDeltas = [],
        private array $toggleCosts = [],
    ) {
    }

    /**
     * Null for the selected value and for parameters that were not priced.
     */
    public function choiceDelta(string $parameter, string $value): ?int
    {
        return $this->choiceDeltas[$parameter][$value] ?? null;
    }

    /**
     * What switching the option on costs, whether it is on or off right now.
     */
    public function toggleCost(string $parameter): ?int
    {
        return $this->toggleCosts[$parameter] ?? null;
    }
}

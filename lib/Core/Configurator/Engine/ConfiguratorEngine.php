<?php

declare(strict_types=1);

namespace Core\Configurator\Engine;

use Core\Configurator\Bom\BillOfMaterials;
use Core\Configurator\Definition\ProductDefinition;
use Core\Configurator\Pricing\PriceList;
use Core\Configurator\Pricing\Quote;

/**
 * input → configuration → assembly → bill of materials → quote, in one call.
 */
final readonly class ConfiguratorEngine
{
    public function __construct(private ConfigurationResolver $resolver)
    {
    }

    /**
     * @param array<string, mixed> $input  raw parameter values; missing ones take their default
     * @param PriceList|null       $prices the tenant's prices; the definition's defaults if null
     */
    public function evaluate(ProductDefinition $definition, array $input, ?PriceList $prices = null): Evaluation
    {
        $resolved = $this->resolver->resolve($definition, $input);
        $assembly = $definition->build($resolved->configuration);
        $bom = BillOfMaterials::fromAssembly($assembly);

        return new Evaluation(
            $definition,
            $resolved,
            $assembly,
            $bom,
            Quote::price($bom, $prices ?? $definition->defaultPrices()),
        );
    }
}

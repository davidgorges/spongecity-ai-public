<?php

declare(strict_types=1);

namespace Core\Configurator\Definition;

use Core\Configurator\Assembly\Assembly;
use Core\Configurator\Engine\Configuration;
use Core\Configurator\Parameter\ParameterSet;
use Core\Configurator\Pricing\PriceList;
use Core\Configurator\Scene\MaterialLibrary;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A product family (patio roof, carport, workbench, …): its parameters, the
 * construction rules that turn a configuration into parts, its materials and
 * the default prices of its parts. Tenants sell a family as a
 * ConfiguratorProduct with their own name and prices.
 */
#[AutoconfigureTag(self::TAG)]
interface ProductDefinition
{
    public const string TAG = 'configurator.product_definition';

    /**
     * Stable identifier stored on ConfiguratorProduct; never change it.
     */
    public static function key(): string;

    public function label(): string;

    /**
     * Always the same parameters in the same order; their bounds may depend on
     * the values resolved so far (parameters earlier in the list).
     */
    public function parameters(Configuration $resolved): ParameterSet;

    /**
     * The construction: every part with its size, position, material and SKU.
     */
    public function build(Configuration $configuration): Assembly;

    public function materials(): MaterialLibrary;

    public function defaultPrices(): PriceList;
}

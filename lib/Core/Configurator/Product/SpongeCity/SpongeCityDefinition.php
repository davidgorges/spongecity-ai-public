<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity;

use Core\Configurator\Assembly\Assembly;
use Core\Configurator\Definition\ProductDefinition;
use Core\Configurator\Definition\StagesScenery;
use Core\Configurator\Engine\Configuration;
use Core\Configurator\Parameter\NumberParameter;
use Core\Configurator\Parameter\ParameterGroup;
use Core\Configurator\Parameter\ParameterSet;
use Core\Configurator\Pricing\PriceList;
use Core\Configurator\Product\SpongeCity\SpongeCityParameter as P;
use Core\Configurator\Product\SpongeCity\SpongeCitySku as Sku;
use Core\Configurator\Scene\MaterialLibrary;
use Core\Configurator\Scene\Setting;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteType;

/**
 * A real place and the measures that make it a sponge: the city around it
 * (buildings, trees, ground from OpenStreetMap) is the customer's site, the
 * interventions are the product, with a bill of quantities and a cost estimate.
 */
final readonly class SpongeCityDefinition implements ProductDefinition, StagesScenery
{
    public const string KEY = 'spongecity';
    public const string LABEL = 'spongecity.label';

    /** prices in euro cents per m² (areas), per piece (trees) */
    private const array PRICES = [
        Sku::TREE => 90000,
        Sku::RAINGARDEN => 18000,
        Sku::PERMEABLE_PAVERS => 11000,
        Sku::PERMEABLE_GRAVEL => 4500,
        Sku::PERMEABLE_GRASS_PAVERS => 7500,
        Sku::PLANTING => 3500,
        Sku::SHADESAIL => 15000,
    ];

    public function __construct(private InterventionBuilder $interventions = new InterventionBuilder())
    {
    }

    public static function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return self::LABEL;
    }

    public function parameters(Configuration $resolved): ParameterSet
    {
        return new ParameterSet(
            new NumberParameter(P::STORM, P::LABEL_STORM, P::STORM_MIN, P::STORM_MAX, 5, P::STORM_DEFAULT, 'mm/h', ParameterGroup::OPTIONS),
            SiteType::parameter(),
        );
    }

    public function build(Configuration $configuration): Assembly
    {
        return $this->interventions->build(Site::of($configuration));
    }

    public function materials(): MaterialLibrary
    {
        return new MaterialLibrary([]);
    }

    public function defaultPrices(): PriceList
    {
        return new PriceList(self::PRICES);
    }

    public function setting(): Setting
    {
        return Setting::Garden;
    }

    public function stage(Configuration $configuration, Assembly $product): Assembly
    {
        return new Assembly();
    }
}

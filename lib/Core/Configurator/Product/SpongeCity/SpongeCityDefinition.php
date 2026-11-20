<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity;

use Core\Configurator\Assembly\Assembly;
use Core\Configurator\Definition\ProductDefinition;
use Core\Configurator\Definition\StagesScenery;
use Core\Configurator\Engine\Configuration;
use Core\Configurator\Parameter\Choice;
use Core\Configurator\Parameter\ChoiceParameter;
use Core\Configurator\Parameter\NumberParameter;
use Core\Configurator\Parameter\ParameterGroup;
use Core\Configurator\Parameter\ParameterSet;
use Core\Configurator\Pricing\PriceList;
use Core\Configurator\Product\SpongeCity\Funding\Applicant;
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

    /**
     * prices in euro cents per m² (areas), per piece (trees, a green roof's set-up per building).
     * Green roofs are retrofit prices: system build-up, root barrier, gravel strip, drainage, crane;
     * not a new waterproofing, not structural reinforcement.
     */
    private const array PRICES = [
        Sku::TREE => 90000,
        Sku::RAINGARDEN => 18000,
        Sku::PERMEABLE_PAVERS => 11000,
        Sku::PERMEABLE_GRAVEL => 4500,
        Sku::PERMEABLE_GRASS_PAVERS => 7500,
        Sku::PLANTING => 3500,
        Sku::SHADESAIL => 15000,
        Sku::GREENROOF_EXTENSIVE => 9500,
        Sku::GREENROOF_INTENSIVE => 19000,
        Sku::GREENROOF_SETUP => 800000,
    ];

    /**
     * What keeping a measure working costs a year, euro cents per unit (m², piece), averaged over
     * LIFECYCLE_YEARS: indicative values for public open space (GALK / FLL care guidance), not a tender.
     * Trees: watering and formative pruning in the first years, then inspection and pruning.
     * Rain gardens: weeding, cutting back, clearing silt from the inlets. Permeable surfaces: sweeping
     * and refilling joints, raking gravel, mowing grass pavers. Green roofs: one or two visits a year
     * (extensive), a roof garden is gardened. A green roof's set-up is paid once.
     */
    private const array MAINTENANCE_PER_YEAR = [
        Sku::TREE => 8000,
        Sku::RAINGARDEN => 600,
        Sku::PERMEABLE_PAVERS => 100,
        Sku::PERMEABLE_GRAVEL => 150,
        Sku::PERMEABLE_GRASS_PAVERS => 150,
        Sku::PLANTING => 400,
        Sku::SHADESAIL => 400,
        Sku::GREENROOF_EXTENSIVE => 150,
        Sku::GREENROOF_INTENSIVE => 600,
        Sku::GREENROOF_SETUP => 0,
    ];
    /** the years the running costs are added up over: about the service life of the surfaces and the time a tree needs to grow */
    public const int LIFECYCLE_YEARS = 20;

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
            new ChoiceParameter(
                P::APPLICANT,
                P::LABEL_APPLICANT,
                array_map(static fn (Applicant $a): Choice => new Choice($a->value, P::LABEL_APPLICANT_CHOICE.$a->value), Applicant::cases()),
                Applicant::Unknown->value,
            ),
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

    /**
     * Yearly running costs in cents per unit, by SKU: priced against the same bill of quantities as
     * the investment (Quote::price), so both cover the same measures.
     */
    public static function maintenancePrices(): PriceList
    {
        return new PriceList(self::MAINTENANCE_PER_YEAR);
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

<?php

declare(strict_types=1);

namespace Core\Configurator\Definition;

use Core\Configurator\Assembly\Assembly;
use Core\Configurator\Engine\Configuration;
use Core\Configurator\Scene\Setting;

/**
 * A product shown in surroundings: the house it is built onto, the terrace,
 * plants, furniture. Staging is kept apart from build() because only the 3D
 * view needs it: prices, the parts list and the option prices are computed
 * from the product alone, many times per render.
 *
 * Scenery parts never have a SKU (Core\Configurator\Scenery has shared
 * pieces); environment hints for the viewer (Assembly::environment()) belong
 * here as well. A product without scenery is shown in the studio
 * (Setting::Studio).
 */
interface StagesScenery
{
    /** The environment the viewer builds around the product and its scenery. */
    public function setting(): Setting;

    /**
     * The scenery around a product, without the product's parts.
     *
     * @param Assembly $product what build() returned for this configuration, e.g. to keep plants clear of it
     */
    public function stage(Configuration $configuration, Assembly $product): Assembly;
}

<?php

declare(strict_types=1);

namespace Core\Configurator\Engine;

use Core\Configurator\Assembly\Assembly;
use Core\Configurator\Bom\BillOfMaterials;
use Core\Configurator\Definition\ProductDefinition;
use Core\Configurator\Definition\StagesScenery;
use Core\Configurator\Pricing\Quote;
use Core\Configurator\Scene\Setting;
use Core\Configurator\Site\Site;
use Core\Configurator\Site\SiteBuilder;

/**
 * One configuration of a product, evaluated: the product's parts (`assembly`),
 * its bill of materials and quote. The scenery around it is staged only when
 * asked for (scene()), so evaluations made just for a price stay cheap.
 */
final class Evaluation
{
    private ?Assembly $scene = null;

    public function __construct(
        public readonly ProductDefinition $definition,
        public readonly ResolvedConfiguration $resolved,
        /** the product alone: what the BOM and the quote come from */
        public readonly Assembly $assembly,
        public readonly BillOfMaterials $bom,
        public readonly Quote $quote,
    ) {
    }

    public function configuration(): Configuration
    {
        return $this->resolved->configuration;
    }

    /** Where the viewer shows the product: its definition's choice, the studio for one without scenery. */
    public function setting(): Setting
    {
        return $this->definition instanceof StagesScenery ? $this->definition->setting() : Setting::Studio;
    }

    /**
     * What the 3D view shows: the product, the scenery its definition stages
     * and the customer's site (SiteType), if it has one.
     */
    public function scene(): Assembly
    {
        if (null === $this->scene) {
            $scene = $this->definition instanceof StagesScenery
                ? $this->assembly->with($this->definition->stage($this->configuration(), $this->assembly))
                : $this->assembly;
            $site = Site::of($this->configuration());
            $this->scene = $site->isEmpty() ? $scene : $scene->with((new SiteBuilder())->build($site));
        }

        return $this->scene;
    }
}

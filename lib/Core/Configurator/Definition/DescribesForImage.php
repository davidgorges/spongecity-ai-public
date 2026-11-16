<?php

declare(strict_types=1);

namespace Core\Configurator\Definition;

use Core\Configurator\Engine\Configuration;

/**
 * A product that describes a configuration for an image model (AI photos): in plain English,
 * what it is, how it stands and what it does NOT have. An image model adds what a product
 * usually has (a patio roof gets glass walls) unless told otherwise.
 */
interface DescribesForImage
{
    /**
     * One or two sentences, e.g. "an anthracite aluminium patio roof, 4.00 m wide and 3.00 m deep,
     * fixed to the house wall, … It is open on all sides: no side walls, no front glazing."
     */
    public function imageBrief(Configuration $configuration): string;

    /** Where the product goes when the customer's photo has no marked place, e.g. "mounted to the house wall". */
    public function imagePlacement(): string;
}

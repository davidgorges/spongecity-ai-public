<?php

declare(strict_types=1);

namespace Core\Configurator\Scene;

/**
 * Hints a product gives the scenery around it (Assembly::environment()),
 * serialised as the scene's `environment` object.
 */
final class Environment
{
    /** stepping stones from the product's front into the garden; default on */
    public const string GARDEN_PATH = 'gardenPath';

    /** the camera looks down on the site from a bird's-eye view instead of standing in the garden; default off */
    public const string AERIAL = 'aerial';

    /** an aerial site among low houses: the generic city around it is a suburb, not perimeter blocks; default off */
    public const string SUBURBAN = 'suburban';

    private function __construct()
    {
    }
}

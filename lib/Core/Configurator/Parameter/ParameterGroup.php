<?php

declare(strict_types=1);

namespace Core\Configurator\Parameter;

/**
 * Sections the configurator form is split into, in this order.
 */
final class ParameterGroup
{
    public const string DIMENSIONS = 'dimensions';
    public const string OPTIONS = 'options';
    public const string EXTRAS = 'extras';
    /** the customer's surroundings (Site\SiteType): scenery, never priced */
    public const string SITE = 'site';

    public const array ORDER = [self::DIMENSIONS, self::OPTIONS, self::EXTRAS, self::SITE];

    private function __construct()
    {
    }
}

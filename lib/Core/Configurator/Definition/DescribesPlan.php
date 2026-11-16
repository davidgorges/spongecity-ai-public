<?php

declare(strict_types=1);

namespace Core\Configurator\Definition;

use Core\Configurator\Engine\Configuration;

/**
 * A product that tells where it stands in plan (millimetres, x to the right
 * seen from the street, z towards the street): the reference an AI agent
 * places the customer's surroundings by (the fence's corners and gates, the
 * patio roof's posts and the house wall).
 */
interface DescribesPlan
{
    /**
     * @return array<string, mixed> named points, lines and outlines in plan, JSON-serialisable
     */
    public function plan(Configuration $configuration): array;
}

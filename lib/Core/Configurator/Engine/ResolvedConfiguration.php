<?php

declare(strict_types=1);

namespace Core\Configurator\Engine;

use Core\Configurator\Parameter\ParameterSet;

final readonly class ResolvedConfiguration
{
    /**
     * @param ParameterSet $parameters the parameters with the bounds that apply to this configuration
     * @param list<string> $adjusted   names of parameters whose input was out of bounds and was corrected
     */
    public function __construct(
        public Configuration $configuration,
        public ParameterSet $parameters,
        public array $adjusted = [],
    ) {
    }
}

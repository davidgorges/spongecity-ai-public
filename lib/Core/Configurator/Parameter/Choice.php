<?php

declare(strict_types=1);

namespace Core\Configurator\Parameter;

final readonly class Choice
{
    /**
     * @param string|null $swatch CSS colour shown next to the option, e.g. for frame colours
     */
    public function __construct(
        public string $value,
        public string $label,
        public ?string $swatch = null,
    ) {
    }
}

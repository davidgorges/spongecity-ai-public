<?php

declare(strict_types=1);

namespace Core\Configurator\Pricing;

use Core\Configurator\Bom\BomLine;

final readonly class QuoteLine
{
    /**
     * @param int|null $unitPrice net cents per unit, null when the SKU has no price
     * @param int|null $total     net cents of the line, null when the SKU has no price
     */
    public function __construct(
        public BomLine $bomLine,
        public ?int $unitPrice,
        public ?int $total,
    ) {
    }

    public function isPriced(): bool
    {
        return null !== $this->total;
    }
}

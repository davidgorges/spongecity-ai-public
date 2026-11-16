<?php

declare(strict_types=1);

namespace Core\Configurator\Pricing;

use Core\Configurator\Bom\BillOfMaterials;

/**
 * The priced bill of materials. A quote with unpriced lines is incomplete: the
 * configurator then shows the total as "from" and the business prices the rest.
 */
final readonly class Quote
{
    /**
     * @param list<QuoteLine> $lines
     */
    public function __construct(
        public array $lines,
        public string $currency,
    ) {
    }

    public static function price(BillOfMaterials $bom, PriceList $prices): self
    {
        $lines = [];
        foreach ($bom as $bomLine) {
            $unitPrice = $prices->priceOf($bomLine->sku);
            $total = null === $unitPrice ? null : (int) round($unitPrice * $bomLine->totalAmount());
            $lines[] = new QuoteLine($bomLine, $unitPrice, $total);
        }

        return new self($lines, $prices->currency);
    }

    /**
     * Net cents of all priced lines.
     */
    public function total(): int
    {
        return array_sum(array_map(static fn (QuoteLine $line): int => $line->total ?? 0, $this->lines));
    }

    public function isComplete(): bool
    {
        foreach ($this->lines as $line) {
            if (!$line->isPriced()) {
                return false;
            }
        }

        return true;
    }
}

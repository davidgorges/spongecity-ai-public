<?php

declare(strict_types=1);

namespace Core\Configurator\Pricing;

use Webmozart\Assert\Assert;

/**
 * Net unit prices in cents by SKU. The unit follows the part's measure: per
 * piece, per metre of cut length or per square metre.
 */
final readonly class PriceList
{
    public const string DEFAULT_CURRENCY = 'EUR';

    /**
     * @param array<string, int> $prices
     */
    public function __construct(
        private array $prices,
        public string $currency = self::DEFAULT_CURRENCY,
    ) {
        Assert::allInteger($prices);
        Assert::allGreaterThanEq($prices, 0);
    }

    public function priceOf(string $sku): ?int
    {
        return $this->prices[$sku] ?? null;
    }

    /**
     * A tenant's own prices on top of the definition's defaults.
     *
     * @param array<string, int> $overrides
     */
    public function withOverrides(array $overrides): self
    {
        return new self([...$this->prices, ...$overrides], $this->currency);
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->prices;
    }
}

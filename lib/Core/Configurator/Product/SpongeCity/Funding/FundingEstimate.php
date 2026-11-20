<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Funding;

use Core\Configurator\Pricing\Quote;

/**
 * What the programmes could pay towards a quote for an applicant: per programme the eligible cost,
 * the share, the amount (capped) and its conditions. An estimate to apply with, never a promise.
 */
final readonly class FundingEstimate
{
    /**
     * @param list<array{programme: FundingProgramme, eligible: int, share: float, amount: int, capped: bool, below_minimum: bool}> $lines cents
     */
    public function __construct(
        public Applicant $applicant,
        public array $lines,
    ) {
    }

    /**
     * @param list<FundingProgramme> $programmes
     */
    public static function of(Quote $quote, Applicant $applicant, array $programmes): self
    {
        $lines = [];
        foreach ($programmes as $programme) {
            $eligible = 0;
            $eligibleAmount = 0.0;
            $capUnits = 0.0;
            foreach ($quote->lines as $line) {
                if (in_array($line->bomLine->sku, $programme->skus, true)) {
                    $eligible += $line->total ?? 0;
                    $eligibleAmount += $line->bomLine->totalAmount();
                }
                if (null !== $programme->capPerSku && $line->bomLine->sku === $programme->capPerSku) {
                    $capUnits += $line->bomLine->totalAmount();
                }
            }
            if (0 === $eligible) {
                continue;
            }
            $share = $programme->shareFor($applicant);
            $belowMinimum = $eligibleAmount < $programme->minEligibleAmount;
            $amount = $belowMinimum ? 0 : (int) round($eligible * $share);
            $cap = null === $programme->capCents ? null : (int) round($programme->capCents * (null === $programme->capPerSku ? 1.0 : max(1.0, $capUnits)));
            $capped = null !== $cap && $amount > $cap;
            $lines[] = [
                'programme' => $programme,
                'eligible' => $eligible,
                'share' => $share,
                'amount' => $capped ? $cap : $amount,
                'capped' => $capped,
                'below_minimum' => $belowMinimum,
            ];
        }

        return new self($applicant, $lines);
    }

    /** cents all programmes together could pay */
    public function total(): int
    {
        return array_sum(array_column($this->lines, 'amount'));
    }
}

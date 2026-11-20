<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Funding;

/**
 * A public funding programme for some of the measures: which SKUs it pays for, what share of their
 * cost per applicant, up to which amount, and from which size. Taken from the programme's own
 * guideline (url, asOf); a programme ends (validUntil) and its terms change, so the estimate says so.
 */
final readonly class FundingProgramme
{
    /**
     * @param list<string>         $skus              the measures it pays for
     * @param array<string, float> $shares            Applicant value → share of the eligible cost (0 to 1); a missing applicant gets nothing
     * @param int|null             $capCents          the most it pays, in cents
     * @param string|null          $capPerSku         the cap applies once per piece of this SKU (e.g. per building with a green roof), else once for the plan
     * @param float                $minEligibleAmount the least eligible quantity (m² of the eligible lines) it funds at all
     * @param list<string>         $conditions        what the applicant must know (apply before starting, who may apply)
     * @param int                  $minEligibleCents  the least eligible cost it funds at all, in cents
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $publisher,
        public string $url,
        public string $asOf,
        public ?string $validUntil,
        public array $skus,
        public array $shares,
        public ?int $capCents = null,
        public ?string $capPerSku = null,
        public float $minEligibleAmount = 0.0,
        public array $conditions = [],
        public int $minEligibleCents = 0,
    ) {
    }

    public function shareFor(Applicant $applicant): float
    {
        if (Applicant::Unknown === $applicant) {
            return [] === $this->shares ? 0.0 : max($this->shares);
        }

        return $this->shares[$applicant->value] ?? 0.0;
    }
}

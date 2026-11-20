<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity\Funding;

use Core\Configurator\Product\SpongeCity\SpongeCitySku as Sku;

/**
 * Hamburg's programmes for green roofs and for unsealing, as their guidelines stood when written
 * down here (asOf). Shares and caps change: the estimate always points to the guideline.
 */
final class HamburgFunding
{
    public const string GREEN_ROOF = 'hamburg_gruendach';
    public const string UNSEALING = 'hamburg_risa_entsiegelung';

    private function __construct()
    {
    }

    /**
     * @return list<FundingProgramme>
     */
    public static function programmes(): array
    {
        return [
            new FundingProgramme(
                self::GREEN_ROOF,
                'Hamburger Gründachförderung',
                'IFB Hamburg',
                'https://www.ifbhh.de/resource/blob/13128/b839eac974aec2c31f236657d52284e7/foerderrichtlinie-hamburger-gruendachfoerderung-2026-data.pdf',
                'Förderrichtlinie 2026',
                '2026-12-31',
                // the build-up from the top of the waterproofing and the care until it has grown in; not the set-up per building
                [Sku::GREENROOF_EXTENSIVE, Sku::GREENROOF_INTENSIVE],
                [Applicant::Private->value => 0.60, Applicant::MediumCompany->value => 0.50, Applicant::LargeCompany->value => 0.40],
                capCents: 100_000_00,
                capPerSku: Sku::GREENROOF_SETUP,
                minEligibleAmount: 20.0,
                conditions: [
                    'Apply to IFB Hamburg and wait for approval before signing any contract: a project counts as started once it is ordered.',
                    'For private owners and companies (60 / 50 / 40 % for small, medium and large ones); public owners are not among the applicants.',
                    'At least 20 m² of vegetation and 8 cm of substrate on an existing roof; up to 100,000 € per building.',
                ],
            ),
            new FundingProgramme(
                self::UNSEALING,
                'RISA Förderprogramm: Entsiegelung',
                'BUKEA / RISA Hamburg',
                'https://www.hamburg.de/politik-und-verwaltung/behoerden/bukea/aktuelles/pressemeldungen/ein-jahr-risa-foerderprogramm-1109508',
                '2025',
                null,
                // unsealed and greened ground: planting, rain gardens, open-jointed surfaces; not closed pavers
                [Sku::PLANTING, Sku::RAINGARDEN, Sku::PERMEABLE_GRASS_PAVERS, Sku::PERMEABLE_GRAVEL],
                // the programme pays 30 to 50 %: the estimate takes the lower end
                [Applicant::Private->value => 0.30, Applicant::MediumCompany->value => 0.30, Applicant::LargeCompany->value => 0.30],
                capCents: 25_000_00,
                minEligibleAmount: 10.0,
                conditions: [
                    'Pays 30 to 50 % of planning, demolition, disposal and greening from 10 m², up to 25,000 €: the estimate takes 30 %.',
                    'Apply before starting; which measures count as unsealing is decided per application.',
                    'Public owners are not among the applicants.',
                ],
            ),
        ];
    }
}

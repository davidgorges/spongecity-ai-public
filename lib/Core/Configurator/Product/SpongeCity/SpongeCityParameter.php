<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity;

final class SpongeCityParameter
{
    /** the design storm in millimetres of rain within one hour */
    public const string STORM = 'storm';
    public const string LABEL_STORM = 'spongecity.storm';

    public const int STORM_MIN = 10;
    public const int STORM_MAX = 120;
    public const int STORM_DEFAULT = 40;

    /** who would apply for funding (Funding\Applicant): decides the share the programmes pay */
    public const string APPLICANT = 'applicant';
    public const string LABEL_APPLICANT = 'spongecity.applicant.label';
    /** + the Applicant value */
    public const string LABEL_APPLICANT_CHOICE = 'spongecity.applicant.';

    private function __construct()
    {
    }
}

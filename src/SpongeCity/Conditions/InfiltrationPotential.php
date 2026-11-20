<?php

declare(strict_types=1);

namespace App\SpongeCity\Conditions;

/**
 * How likely rainwater can infiltrate the ground, as Hamburg's Versickerungspotentialkarte
 * classes it (from the depth of the unsaturated soil above the groundwater). Best first:
 * possible (> 5 m, any facility), likely (2 to 5 m), limited (1 to 2 m), unlikely (< 1 m).
 * A preliminary assessment: it tells whether infiltration is worth investigating, never
 * an infiltration rate.
 */
enum InfiltrationPotential: string
{
    case Possible = 'possible';
    case Likely = 'likely';
    case Limited = 'limited';
    case Unlikely = 'unlikely';
    case Unknown = 'unknown';

    /** the map's own words (versickerungswahrscheinlichkeit) */
    private const array GERMAN = [
        'möglich' => self::Possible,
        'wahrscheinlich' => self::Likely,
        'eingeschränkt' => self::Limited,
        'unwahrscheinlich' => self::Unlikely,
    ];

    public static function fromGerman(string $value): self
    {
        return self::GERMAN[mb_strtolower(trim($value))] ?? self::Unknown;
    }

    /** whether a facility that lets water soak away (an infiltrating rain garden, a trench) may be planned here */
    public function soaksAway(): bool
    {
        return self::Possible === $this || self::Likely === $this;
    }

    /** what it means for planning, for the agent */
    public function guidance(): string
    {
        return match ($this) {
            self::Possible => 'Infiltration is possible with any facility (surface, swale, trench, shaft): the groundwater lies deep; confirm with a percolation test.',
            self::Likely => 'Infiltration is likely possible (surface or swale infiltration); confirm with a percolation test.',
            self::Limited => 'Infiltration is limited (shallow groundwater or less permeable soil): prefer retention with throttled overflow; investigate before relying on infiltration.',
            self::Unlikely => 'Infiltration is unlikely: plan retention, evaporation and reuse (e.g. lined rain gardens, green roofs) rather than infiltration.',
            self::Unknown => 'Not classified by the map.',
        };
    }
}

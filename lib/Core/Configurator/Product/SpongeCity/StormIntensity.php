<?php

declare(strict_types=1);

namespace Core\Configurator\Product\SpongeCity;

/**
 * How heavy a rain of so many millimetres within one hour is, in words a person knows: the DWD's
 * warning levels for heavy rain (15 mm/h heavy rain, 25 mm/h severe, 40 mm/h extreme), below them a
 * downpour, and from 80 mm/h the rare events that flood streets for hours.
 */
enum StormIntensity: string
{
    case Downpour = 'downpour';
    case Heavy = 'heavy';
    case Severe = 'severe';
    case Cloudburst = 'cloudburst';
    case Extreme = 'extreme';

    /** the millimetres per hour from which each level starts, highest first */
    private const array FROM_MM = [
        'extreme' => 80,
        'cloudburst' => 40,
        'severe' => 25,
        'heavy' => 15,
    ];

    public static function of(int $millimetresPerHour): self
    {
        foreach (self::FROM_MM as $level => $from) {
            if ($millimetresPerHour >= $from) {
                return self::from($level);
            }
        }

        return self::Downpour;
    }
}
